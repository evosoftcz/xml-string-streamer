<?php declare(strict_types=1);
namespace Prewk\XmlStringStreamer\Parser;
/**
 * xml-string-streamer StringWalker parser
 * 
 * @package xml-string-streamer
 * @author  Oskar Thornblad <oskar.thornblad@gmail.com>
 */

use Exception;
use Prewk\XmlStringStreamer\ParserInterface;
use Prewk\XmlStringStreamer\StreamInterface;
use RuntimeException;

/**
 * The string walker parser builds the XML nodes by fetching one element at a time until a certain depth is re-reached
 */
class StringWalker implements ParserInterface
{
    /**
     * Holds the parser configuration
     * @var array
     */
    protected array $options;

    /**
     * Is this the first run?
     * @var boolean
     */
    protected bool $firstRun;

    /**
     * What depth are we currently at?
     * @var integer
     */
    protected int $depth;

    /**
     * The latest chunk from the stream
     * @var string
     */
    protected string $chunk;

    /**
     * Last XML node in the making, used for anti-freeze detection
     * @var null|string
     */
    protected ?string $lastChunk;

    /**
     * XML node in the making
     * @var null|string
     */
    protected ?string $shaved;

    /**
     * Whether to capture or not
     * @var boolean
     */
    protected bool $capture;

    /**
     * If extractContainer is true, this will grow with the XML captured before and after the specified capture depth
     * @var string
     */
    protected string $containerXml;

    /**
     * Parser constructor
     * @param array $options An options array
     */
    public function __construct(array $options = [])
    {
        $this->reset();

        $this->options = array_merge([
            "captureDepth" => 2,
            "expectGT" => false,
            "tags" => [
                ["<?", "?>", 0],
                ["<!--", "-->", 0],
                ["<![CDATA[", "]]>", 0],
                ["<!", ">", 0],
                ["</", ">", -1],
                ["<", "/>", 0],
                ["<", ">", 1],
            ],
            "tagsWithAllowedGT" => [
                ["<!--", "-->"],
                ["<![CDATA[", "]]>"],
            ],
            "extractContainer" => false,
        ], $options);
    }

    /**
     * Shaves off the next element from the chunk
     * @return string[]|bool Either a shaved off element array(0 => Captured element, 1 => Data from last shaving point up to and including captured element) or false if one could not be obtained
     */
    protected function shave(): array|bool
    {
        preg_match("/<[^>]+>/", $this->chunk, $matches, PREG_OFFSET_CAPTURE);

        if (isset($matches[0], $matches[0][0], $matches[0][1])) {
            [$captured, $offset] = $matches[0];

            if ($this->options["expectGT"]) {
                // Some elements support > inside
                foreach ($this->options["tagsWithAllowedGT"] as $tag) {
                    [$opening, $closing] = $tag;

                    if (substr($captured, 0, strlen($opening)) === $opening) {
                        // We have a match, our preg_match may have ended too early
                        // Most often, this isn't the case
                        if (substr($captured, -1 * strlen($closing)) !== $closing) {
                            // In this case, the preg_match ended too early, let's find the real end
                            $position = strpos($this->chunk, $closing);
                            if ($position === false) {
                                // We need more XML!

                                return false;
                            }

                            // We found the end, modify $captured
                            $captured = substr($this->chunk, $offset, $position + strlen($closing) - $offset);
                        }
                    }
                }
            }

            // Data in between
            $data = substr($this->chunk, 0, $offset);

            // Shave from chunk
            $this->chunk = substr($this->chunk, $offset + strlen($captured));

            return array($captured, $data . $captured);
        }

        return false;
    }

    /**
     * Extract XML compatible tag head and tail
     * @param  string  $element  XML element
     * @return string[] 0 => Opening tag, 1 => Closing tag
     */
    protected function getEdges(string $element): array
    {
        // TODO: Performance tuning possible here by not looping

        foreach ($this->options["tags"] as $tag) {
            [$opening, $closing, $depth] = $tag;

            if (str_starts_with($element, $opening) && str_ends_with($element, $closing)) {
                return $tag;
            }
        }

        return [];
    }
    
    /**
     * The shave method must be able to request more data even though there isn't any more to fetch from the stream, this method wraps the getChunk call so that it returns true as long as there is XML data left
     * @param  StreamInterface $stream The stream to read from
     * @return bool Returns whether there is more XML data or not
     */
    protected function prepareChunk(StreamInterface $stream): bool
    {
        if (!$this->firstRun && is_null($this->shaved)) {
            // We're starting again after a flush
            $this->shaved = "";
            return true;
        }

        if (is_null($this->shaved)) {
            $this->shaved = "";
        }

        $newChunk = $stream->getChunk();

        if ($newChunk !== false) {
            $this->chunk .= $newChunk;

            return true;
        }

        if (trim($this->chunk) !== "" && $this->chunk !== $this->lastChunk) {
            // Update anti-freeze protection chunk
            $this->lastChunk = $this->chunk;
            // Continue
            return true;
        }

        return false;
    }

    /**
     * Get the extracted container XML, if called before the whole stream is parsed, the XML returned will most likely be invalid due to missing closing tags
     * @return string XML string
     * @throws Exception if the extractContainer option isn't true
     */
    public function getExtractedContainer(): string
    {
        if (!$this->options["extractContainer"]) {
            throw new RuntimeException("This method requires the 'extractContainer' option to be true");
        }

        return $this->containerXml;
    }

    /**
     * Tries to retrieve the next node or returns false
     * @param  StreamInterface $stream The stream to use
     * @return string|bool             The next xml node or false if one could not be retrieved
     */
    public function getNodeFrom(StreamInterface $stream): bool|string
    {
        // Iterate and append to $this->chunk
        while ($this->prepareChunk($stream)) {
            $this->firstRun = false;
            // Shave off elements
            while ($shaved = $this->shave()) {
                [$element, $data] = $shaved;

                // Analyze element
                [$opening, $closing, $depth] = $this->getEdges($element);

                // Update depth
                $this->depth += $depth;

                $flush = false;
                $captureOnce = false;

                // Capture or don't?
                if ($this->depth === $this->options["captureDepth"] && $depth > 0) {
                    // Yes, we've just entered capture depth, start capturing
                    $this->capture = true;
                } else if ($this->depth === $this->options["captureDepth"] - 1 && $depth < 0) {
                    // No, we've just exited capture depth, stop capturing and prepare for flush      
                    $flush = true;
                    $this->capture = false;
                    
                    // ..but include this last node
                    $this->shaved .= $data;
                } else if ($this->options["extractContainer"] && $this->depth < $this->options["captureDepth"]) {
                    // We're outside of our capture scope, save to the special buffer if extractContainer is true
                    $this->containerXml .= $element;
                } else if ($depth === 0 && $this->depth + 1 === $this->options["captureDepth"]) {
                    // Self-closing element - capture this element and flush but don't start capturing everything yet
                    $captureOnce = true;
                    $flush = true;
                }

                // Capture the last retrieved node
                if ($this->capture || $captureOnce) {
                    $this->shaved .= $data;
                }

                if ($flush) {
                    // Flush the whole node and start over on the next
                    $flush = $this->shaved;
                    $this->shaved = null;

                    return $flush;
                }
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->firstRun = true;
        $this->depth = 0;
        $this->chunk = '';
        $this->lastChunk = null;
        $this->shaved = null;
        $this->capture = false;
        $this->containerXml = "";
    }
}
