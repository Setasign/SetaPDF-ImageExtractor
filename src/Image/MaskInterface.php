<?php

declare(strict_types=1);

namespace setasign\SetaPDF\ImageExtractor\Image;

/**
 * Interface MaskInterface
 *
 * This interface is used to read "alpha" values
 */
interface MaskInterface
{
    /**
     * Returns the corresponding alpha value
     *
     * @param int $x
     * @param int $y
     * @param AbstractImage $caller
     * @return int
     */
    public function getCorrespondingAlphaValue(int $x, int $y, AbstractImage $caller): int;

    /**
     * Returns if the AbstractImage should read the MaskInterface pixel by pixel or if it should get the stream afterwards
     * Note that the interface always should be able to read "alpha" values
     *
     * @return bool
     */
    public function isReadingPixelByPixel(): bool;

    /**
     * Returns if the Mask is able to output a blob
     *
     * @return bool
     */
    public function canOutputBlob(): bool;

    /**
     * Cleans the instance
     *
     * @return void
     */
    public function cleanUp();

    /**
     * Returns the image blob as a string
     *
     * @param AbstractImage $caller
     * @throws \BadFunctionCallException
     * @return string
     */
    public function getBlob(AbstractImage $caller): string;
}

