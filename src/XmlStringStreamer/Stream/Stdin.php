<?php declare(strict_types=1);
namespace Prewk\XmlStringStreamer\Stream;

class Stdin extends File
{
    /**
     * @param  int         $chunkSize
     * @param  mixed|null  $chunkCallback
     */
    public function __construct(int $chunkSize = 1024, mixed $chunkCallback = null)
    {
        parent::__construct(fopen('php://stdin', 'rb'), $chunkSize, $chunkCallback);
    }
}