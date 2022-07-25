<?php

declare(strict_types=1);

namespace setasign\SetaPDF\ImageExtractor;

/**
 * Class ImageProcessor
 */
class ImageProcessor
{
    public const DETAIL_LEVEL_FULL = 0;
    public const DETAIL_LEVEL_ONLY_XOBJECT = 1;

    protected string $_stream;

    protected ?\SetaPDF_Core_Type_Dictionary $_resources;

    protected ?\SetaPDF_Core_Canvas_GraphicState $_graphicState;

    protected ?\SetaPDF_Core_Parser_Content $_contentParser = null;

    protected array $_result = [];

    protected int $_detailLevel = self::DETAIL_LEVEL_ONLY_XOBJECT;
    protected bool $_switchWidthAndHeight = false;

    /**
     * The constructor.
     *
     * @param string $contentStream
     * @param \SetaPDF_Core_Type_Dictionary $resources
     * @param \SetaPDF_Core_Canvas_GraphicState|null $graphicState
     */
    public function __construct(
        string $contentStream,
        \SetaPDF_Core_Type_Dictionary $resources,
        \SetaPDF_Core_Canvas_GraphicState $graphicState = null
    ) {
        $this->_stream = $contentStream;
        $this->_resources = $resources;
        $this->_graphicState = $graphicState ?? new \SetaPDF_Core_Canvas_GraphicState();
    }

    /**
     * Sets the detail level,
     *   if detail level is ImageProcessor::DETAIL_LEVEL_FULL it will give extra information such as width, height,
     *   current graphics state, position and such.
     *
     *   if detail level is ImageProcessor::DETAIL_LEVEL_ONLY_XOBJECT it will just return an array with xObjects.
     *
     * @param int $detailLevel
     */
    public function setDetailLevel(int $detailLevel): void
    {
        $this->_detailLevel = $detailLevel;
    }

    /**
     * Release objects to free memory.
     *
     * After calling this method the instance of this object is unusable!
     *
     * @return void
     */
    public function cleanUp(): void
    {
        $this->_stream = '';
        $this->_resources = null;
        $this->_graphicState = null;

        if ($this->_contentParser instanceof \SetaPDF_Core_Parser_Content) {
            $this->_contentParser->cleanUp();
        }

        $this->_contentParser = null;
    }

    /**
     * Get the graphic state.
     *
     * @return \SetaPDF_Core_Canvas_GraphicState
     */
    public function getGraphicState(): \SetaPDF_Core_Canvas_GraphicState
    {
        return $this->_graphicState;
    }

    /**
     * Process the content stream and return the resolved data.
     *
     * @return array
     * @throws \SetaPDF_Exception_NotImplemented
     * @throws \SetaPDF_Core_Type_Exception
     */
    public function process(): array
    {
        if ($this->_detailLevel === self::DETAIL_LEVEL_FULL) {
            // process everything (graphics state, image pos, ...)

            // parse the content stream
            $parser = $this->_getContentParser();
            $parser->process();

        } elseif($this->_detailLevel === self::DETAIL_LEVEL_ONLY_XOBJECT) {
            // process only the images

            // get all the images
            $resultCount = \preg_match_all('~/(\S*)\s+Do~i', $this->_stream, $result);

            // make sure that we found any image
            if ($resultCount === 0) {
                return $this->_result;
            }

            // iterate through the images
            foreach ($result[1] as $xObject) {
                // change the format of the image to one that self::_onFormXObject() accepts
                $xObject = [new \SetaPDF_Core_Type_Name($xObject, true)];

                // create a new image xObject
                $this->_onFormXObject($xObject, '');
            }
        } else {
            throw new \BadMethodCallException('Unknown detail level.');
        }

        // return the stored images
        return $this->_result;
    }

    protected function _getContentParser(): \SetaPDF_Core_Parser_Content
    {
        if ($this->_contentParser === null) {
            $this->_contentParser = new \SetaPDF_Core_Parser_Content($this->_stream);
            $this->_contentParser->registerOperator(['q', 'Q'], [$this, '_onGraphicStateChange']);
            $this->_contentParser->registerOperator('cm', [$this, '_onCurrentTransformationMatrix']);
            $this->_contentParser->registerOperator('Do', [$this, '_onFormXObject']);
            $this->_contentParser->registerOperator('BI', [$this, '_onInlineImage']);
        }

        return $this->_contentParser;
    }

    /**
     * Callback for the content parser which is called if a graphic state token (q/Q)is found.
     *
     * @param array $arguments
     * @param string $operator
     */
    public function _onGraphicStateChange(array $arguments, string $operator): void
    {
        if ($operator === 'q') {
            $this->getGraphicState()->save();
        } else {
            $this->getGraphicState()->restore();
        }
    }

    /**
     * Callback for the content parser which is called if a "cm" token is found.
     *
     * @param array $arguments
     * @param string $operator
     */
    public function _onCurrentTransformationMatrix(array $arguments, string $operator): void
    {
        $this->getGraphicState()->addCurrentTransformationMatrix(
            $arguments[0]->getValue(), $arguments[1]->getValue(),
            $arguments[2]->getValue(), $arguments[3]->getValue(),
            $arguments[4]->getValue(), $arguments[5]->getValue()
        );
    }

    /**
     * Callback for the content parser which is called if a "Do" operator/token is found.
     *
     * @param array $arguments
     * @param string $operator
     *
     * @throws \SetaPDF_Exception_NotImplemented
     * @throws \SetaPDF_Core_Type_Exception
     */
    public function _onFormXObject(array $arguments, string $operator): void
    {
        $xObjects = $this->_resources->getValue(\SetaPDF_Core_Resource::TYPE_X_OBJECT);
        if ($xObjects === null) {
            return;
        }

        try {
            $name = \SetaPDF_Core_Type_Name::ensureType($arguments[0]);
        } catch (\SetaPDF_Core_Type_Exception $e) {
            return;
        }

        $xObjects = \SetaPDF_Core_Type_Dictionary::ensureType($xObjects);
        $xObject = \SetaPDF_Core_Type_IndirectReference::ensureType($xObjects->getValue($name->getValue()));
        $xObject = \SetaPDF_Core_XObject::get($xObject);

        if ($xObject instanceof \SetaPDF_Core_XObject_Form) {
            /* In that case we need to create a new instance of the processor and process
             * the form xobjects stream.
             */
            $stream = $xObject->getStreamProxy()->getStream();
            $resources = $xObject->getCanvas()->getResources(false);
            if ($resources === false) {
                return;
            }

            $gs = $this->getGraphicState();
            $gs->save();
            $dict = $xObject->getIndirectObject()->ensure()->getValue();
            $matrix = $dict->getValue('Matrix');
            if ($matrix) {
                $matrix = $matrix->ensure()->toPhp();
                $gs->addCurrentTransformationMatrix(
                    $matrix[0], $matrix[1], $matrix[2], $matrix[3], $matrix[4], $matrix[5]
                );
            }

            $processor = new self($stream, $resources, $gs);
            $processor->setDetailLevel($this->_detailLevel);

            foreach ($processor->process() AS $image) {
                $this->_result[] = $image;
            }

            $gs->restore();

            $processor->cleanUp();

        } else {
            try {
                // We don't need ImageMask images (they are really used by some strange PDF generation tools)
                $xObjectDict = \SetaPDF_Core_Type_Stream::ensureType($xObject->getIndirectObject())->getValue();
                $isMask = \SetaPDF_Core_Type_Dictionary_Helper::getValue($xObjectDict, 'ImageMask', false, true);
//                if ($isMask) {
//                    return;
//                }
            } catch (\SetaPDF_Core_Type_Exception $e) {
                return;
            }

            // we only need the image
            if ($this->_detailLevel === self::DETAIL_LEVEL_ONLY_XOBJECT) {
                // add the new image
                $this->_result[] = [
                    'type' => 'xObject',
                    'xObject' => $xObject,
                    'isMask' => $isMask
                ];
                return;
            }

            // we have an image object, calculate its outer points in user space
            $gs = $this->getGraphicState();
            $ll = $gs->toUserSpace(new \SetaPDF_Core_Geometry_Vector(0, 0, 1));
            $ul = $gs->toUserSpace(new \SetaPDF_Core_Geometry_Vector(0, 1, 1));
            $ur = $gs->toUserSpace(new \SetaPDF_Core_Geometry_Vector(1, 1, 1));
            $lr = $gs->toUserSpace(new \SetaPDF_Core_Geometry_Vector(1, 0, 1));

            // ...and match some further information
            $this->_result[] = [
                'type' => 'xObject',
                'll' => $ll->toPoint(),
                'ul' => $ul->toPoint(),
                'ur' => $ur->toPoint(),
                'lr' => $lr->toPoint(),
                'width' => $ur->subtract($ll)->getX(),
                'height' => $ur->subtract($ll)->getY(),
                'resolutionX' => $xObject->getWidth() / $ur->subtract($ll)->getX() * 72,
                'resolutionY' => $xObject->getHeight() / $ur->subtract($ll)->getY() * 72,
                'pixelWidth' => $xObject->getWidth(),
                'pixelHeight' => $xObject->getHeight(),
                'xObject' => $xObject,
                'isMask' => $isMask
            ];
        }
    }

    /**
     * Callback for inline image operator
     *
     * @param array $arguments
     * @param string $operator
     * @return false|void
     */
    public function _onInlineImage($arguments, $operator)
    {
        $parser = $this->_contentParser->getParser();
        $reader = $parser->getReader();

        $pos = $reader->getPos();
        $offset = $reader->getOffset();

        // skip over inline images to increase speed
        // todo implement inline images instead
        while (
            (\preg_match(
                '/EI[\x00\x09\x0A\x0C\x0D\x20]/',
                $reader->getBuffer(),
                $m,
                PREG_OFFSET_CAPTURE
            )) === 0
        ) {
            if ($reader->increaseLength(1000) === false) {
                return false;
            }
        }

        $parser->reset($pos + $offset + $m[0][1] + strlen($m[0][0]));
    }
}
