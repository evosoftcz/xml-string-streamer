<?php declare(strict_types=1);
namespace Prewk\XmlStringStreamer;

/**
 * xml-string-streamer Stream interface
 * 
 * @package xml-string-streamer
 * @author  Oskar Thornblad <oskar.thornblad@gmail.com>
 */
use Exception;

/**
 * Interface describing a stream provider
 */
interface StreamInterface
{
	/**
	 * Gets the next chunk form the stream if one is available
	 * @return bool|string The next chunk if available, or false if not available
	 */
    public function getChunk(): bool|string;

    /**
     * Is the stream seekable?
     * @return bool
     */
    public function isSeekable(): bool;

    /**
     * Rewind the stream
     * @return void
     * @throws Exception if the stream isn't seekable
     */
    public function rewind(): void;
}