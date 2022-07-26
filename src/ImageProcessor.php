<?php

declare(strict_types=1);

namespace setasign\SetaPDF\ImageExtractor;

/**
 * Class ImageProcessor
 */
class ImageProcessor
{
    protected string $_stream;

    protected ?\SetaPDF_Core_Type_Dictionary $_resources;

    protected ?\SetaPDF_Core_Canvas_GraphicState $_graphicState;

    protected ?\SetaPDF_Core_Parser_Content $_contentParser = null;

    protected array $_result = [];

    protected bool $_switchWidthAndHeight = false;

    /**
     * The constructor.
     *
     * @param string $contentStream
     * @param bool $switchWidthAndHeight
     * @param \SetaPDF_Core_Type_Dictionary $resources
     * @param \SetaPDF_Core_Canvas_GraphicState|null $graphicState
     */
    public function __construct(
        string $contentStream,
        bool $switchWidthAndHeight,
        \SetaPDF_Core_Type_Dictionary $resources,

        \SetaPDF_Core_Canvas_GraphicState $graphicState = null
    ) {
        $this->_stream = $contentStream;
        $this->_switchWidthAndHeight = $switchWidthAndHeight;
        $this->_resources = $resources;
        $this->_graphicState = $graphicState ?? new \SetaPDF_Core_Canvas_GraphicState();
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
        // parse the content stream
        $parser = $this->_getContentParser();
        $parser->process();

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

            $processor = new self($stream, $this->_switchWidthAndHeight, $resources, $gs);

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
            } catch (\SetaPDF_Core_Type_Exception $e) {
                return;
            }

            // ...and match some further information
            $result = $this->_getNewResult($xObject->getWidth(), $xObject->getHeight());
            $result['type'] = 'xObject';
            $result['xObject'] = $xObject;
            $result['isMask'] = $isMask;
            $this->_result[] = $result;
        }
    }

    /**
     * Helper method to create a result entry.
     *
     * @param numeric $pixelWidth
     * @param numeric $pixelHeight
     * @return array
     */
    protected function _getNewResult($pixelWidth, $pixelHeight)
    {
        // we have an image object, calculate its outer points in user space
        $gs = $this->getGraphicState();
        $ll = $gs->toUserSpace(new \SetaPDF_Core_Geometry_Vector(0, 0, 1));
        $ul = $gs->toUserSpace(new \SetaPDF_Core_Geometry_Vector(0, 1, 1));
        $ur = $gs->toUserSpace(new \SetaPDF_Core_Geometry_Vector(1, 1, 1));
        $lr = $gs->toUserSpace(new \SetaPDF_Core_Geometry_Vector(1, 0, 1));

        // ...and match some further information
        $width  = \abs($this->_switchWidthAndHeight ? $ur->subtract($ll)->getY() : $ur->subtract($ll)->getX());
        $height = \abs($this->_switchWidthAndHeight ? $ur->subtract($ll)->getX() : $ur->subtract($ll)->getY());

        return [
            'll' => $ll->toPoint(),
            'ul' => $ul->toPoint(),
            'ur' => $ur->toPoint(),
            'lr' => $lr->toPoint(),
            'width' => $width,
            'height' => $height,
            'resolutionX' => $pixelWidth / $width * 72,
            'resolutionY' => $pixelHeight / $height * 72,
            'pixelWidth' => $pixelWidth,
            'pixelHeight' => $pixelHeight
        ];
    }

    /**
     * Callback for inline image operator
     *
     * @return false|void
     * @throws \SetaPDF_Core_Exception
     * @throws \SetaPDF_Core_Parser_Pdf_InvalidTokenException
     */
    public function _onInlineImage()
    {
        $parser = $this->_contentParser->getParser();

        $keyAbbr = [
            'BPC' => 'BitsPerComponent',
            'CS' => 'ColorSpace',
            'D' => 'Decode',
            'DP' => 'DecodeParms',
            'F' => 'Filter',
            'H' => 'Height',
            'IM' => 'ImageMask',
            'I' => 'Interpolate',
            'W' => 'Width'
        ];

        $csAbbr = [
            'G' => new \SetaPDF_Core_Type_Name('DeviceGray', true),
            'RGB' => new \SetaPDF_Core_Type_Name('DeviceRGB', true),
            'CMYK' => new \SetaPDF_Core_Type_Name('DeviceCMYK', true),
            'I' => new \SetaPDF_Core_Type_Name('Indexed', true),
        ];

        $data = new \SetaPDF_Core_Type_Dictionary();
        while (true) {
            $key = $parser->readValue();
            if (!$key || $key->value === 'ID') {
                break;
            }

            $value = $parser->readValue();
            if (!$value) {
                break;
            }

            $key = $parser->convertToObject($key);

            $name = $keyAbbr[$key->getValue()] ?? \SetaPDF_Core_Type_Name::ensureType($key)->getValue();
            $value = $parser->convertToObject($value);
            if ($name === 'ColorSpace') {
                $value = $csAbbr[$value->getValue()] ?? $value;
            }

            $data[$name] = $value;
        }

        $reader = $parser->getReader();
        $reader->readByte(); // skip space after ID token. TODO: How does ASCIIHexDecode works here?

        $pos = $reader->getPos();
        $offset = $reader->getOffset();

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

        $streamData = substr($reader->getBuffer(), 0, $m[0][1]);
        $parser->reset($pos + $offset + $m[0][1] + strlen($m[0][0]));

        $stream = new \SetaPDF_Core_Type_Stream($data, $streamData);

        $pixelWidth = (int)\SetaPDF_Core_Type_Dictionary_Helper::getValue($data, 'Width', 0, true);
        $pixelHeight = (int)\SetaPDF_Core_Type_Dictionary_Helper::getValue($data, 'Height', 0, true);
        $result = $this->_getNewResult($pixelWidth, $pixelHeight);
        $result['type'] = 'inlineImage';
        $result['isMask'] = \SetaPDF_Core_Type_Dictionary_Helper::getValue($data, 'ImageMask', false, true);
        $result['stream'] = $stream;

        $this->_result[] = $result;
    }
}
