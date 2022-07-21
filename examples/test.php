<?php

declare(strict_types=1);

use setasign\SetaPDF\ImageExtractor\ImageExtractor;

set_time_limit(180);

require_once __DIR__ . '/../vendor/autoload.php';

$folders = array_filter([
    realpath(__DIR__ . '/../../SetaPDF/tests/unit/SetaPDF/Core/_files/images/pdfs/') . '/*.pdf',
    realpath(__DIR__ . '/../../SetaPDF/tests/unit/SetaPDF/Core/_files/') . '/*.pdf',
    realpath(__DIR__ . '/../../SetaPDF/tests/unit/SetaPDF/Core/_files/xObjects/') . '/*.pdf',
    realpath(__DIR__ . '/../../SetaPDF/tests/unit/SetaPDF/Core/_files/customers/') . '/*/*.pdf',
    realpath(__DIR__ . '/../files/') . '/*.pdf',
    realpath(__DIR__ . '/../files/') . '/*.PDF'
]);

$files = [];
foreach ($folders as $folder) {
    foreach (glob($folder) as $path) {
        $files[$path] = $path;
    }
}

if (!isset($_GET['f']) || !isset($files[$_GET['f']])) {
    foreach ($files as $path) {
        echo '<a href="test.php?f=' . urlencode($path) . '">' . htmlspecialchars($path) . '</a><br/>';
    }
    die();
}

$directory = 'extractedImages/';

$totalTimeIm = 0;
$totalTimeGD = 0;

$document = SetaPDF_Core_Document::loadByFilename($_GET['f']);
$pageCount = $document->getCatalog()->getPages()->count();

echo '<table bgcolor="#adff2f" border="1"><th><tr><td>Output GD</td><td>GD image</td><td>Output IM</td><td>IM image</td></tr></th>';

$imageCount = 0;
$totalStartTime = microtime(true);

for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
    $images = ImageExtractor::getImagesByPageNo($document, $pageNo);

    foreach ($images as $imageData) {
        /**
         * @var $xObject SetaPDF_Core_XObject_Image
         */
        $xObject = $imageData['xObject'];
        $xObjectId = $xObject->getIndirectObject()->getObjectId();

        $imageCount++;

        echo '<tr><td colspan="6">' . memory_get_usage() . '</td></tr>';

        $im = null;
        $gd = null;
        $image = null;

        echo '<tr>';
        echo '<td>';
        try {
            $startTime = microtime(true);
            $gd = ImageExtractor::xObjectToImage($xObject, ImageExtractor::GD);
            $timeNeeded = (microtime(true) - $startTime);
            $totalTimeGD += $timeNeeded;
            echo 'finished in: ' . $timeNeeded;

            ob_start();
            imagepng($gd);
            $image = ob_get_clean();
            imagedestroy($gd);
            unset($gd);
        } catch (Exception $e) {
            echo $e;
        }

        echo '</td><td>';
        if ($image) {
            echo '<img src="data:image/png;base64,' . base64_encode($image) . '"/>';
        }
        echo '</td><td>';

        $image = null;
        try {
            $startTime = microtime(true);
            $im = ImageExtractor::xObjectToImage($xObject, ImageExtractor::IMAGICK);
            $timeNeeded = (microtime(true) - $startTime);
            $totalTimeIm += $timeNeeded;
            echo 'finished in: ' . $timeNeeded;
            $im->setImageFormat('png');
            $image = $im->getImageBlob();
            $im->destroy();
            unset($im);
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        echo '</td><td>';
        if ($image) {
            echo '<img src="data:image/png;base64,' . base64_encode($image) . '"/>';
        }
        echo '</td>';



        // extra informations

        echo '<td>' . $pageNo . '</td>';
        echo '<td>' . $xObjectId . '</td>';

        echo '<td>';
        echo '<pre>';
        var_dump($xObject->getIndirectObject()->ensure()->getValue()->toPhp());
        echo '</pre>';
        echo '</td>';

        try {
            echo '<td>' . $xObject->getColorSpace()->getFamily() . '</td>';
        } catch (Exception $e) {

        }

        echo '</tr>';
    }
}
echo '</table>';
