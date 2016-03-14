<?php
/**
 * Import.php
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfImage
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-image
 *
 * This file is part of tc-lib-pdf-image software library.
 */

namespace Com\Tecnick\Pdf\Image;

use \Com\Tecnick\File\File;
use \Com\Tecnick\File\Byte;
use \Com\Tecnick\Pdf\Image\Jpeg;
use \Com\Tecnick\Pdf\Image\Png;
use \Com\Tecnick\Pdf\Image\Exception as ImageException;

/**
 * Com\Tecnick\Pdf\Image\Import
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfImage
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2016 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-image
 */
class Import
{
    /**
     * Cache used to store imported image data
     *
     * @var array
     */
    protected static $cache = array();

    /**
     * Native image types and associated importing class
     * (image types for which we have an import method)
     *
     * @var array
     */
    private static $native = array(
        IMAGETYPE_PNG  => 'Png',
        IMAGETYPE_JPEG => 'Jpeg',
    );

    /**
     * Lossless image types
     *
     * @var array
     */
    protected static $lossless = array(
        IMAGETYPE_GIF,
        IMAGETYPE_PNG,
        IMAGETYPE_PSD,
        IMAGETYPE_BMP,
        IMAGETYPE_WBMP,
        IMAGETYPE_XBM,
        IMAGETYPE_TIFF_II,
        IMAGETYPE_TIFF_MM,
        IMAGETYPE_IFF,
        IMAGETYPE_SWC,
        IMAGETYPE_ICO,
    );

    /**
     * Map number of channels with color space name
     *
     * @var array
     */
    protected static $colspacemap = array(
        1 => 'DeviceGray',
        3 => 'DeviceRGB',
        4 => 'DeviceCMYK',
    );

    /**
     * Get the original image raw data
     *
     * @param string $image    Image file name, URL or a '@' character followed by the image data string.
     *                         To link an image without embedding it on the document, set an asterisk character
     *                         before the URL (i.e.: '*http://www.example.com/image.jpg').
     * @param int    $width    New width in pixels or null to keep the original value
     * @param int    $height   New height in pixels or null to keep the original value
     * @param int    $quality  Quality for JPEG files (0 = max compression; 100 = best quality, bigger file).
     * @param bool   $defprint Indicate if the image is the default for printing. when used as alternative image.
     *
     * @return array Image raw data array
     */
    public function import($image, $width = null, $height = null, $quality = 100, $defprint = false)
    {
        $quality = max(0, min(100, $quality));
        $imgkey = $this->getKey($image, intval($width), intval($height), $quality);

        if (isset(self::$cache[$imgkey])) {
            // retrieve cached data
            return self::$cache[$imgkey];
        }

        $data = $this->getRawData($image);
        $data['key'] = $imgkey;
        $data['defprint'] = $defprint;

        if ($width = null) {
            $width = $data['width'];
        }
        $width = max(0, intval($width));
        if ($height = null) {
            $height = $data['height'];
        }
        $height = max(0, intval($height));

        if ((!$data['native']) || ($width != $data['width']) || ($height != $data['height'])) {
            $data = $this->getResizedRawData($data, $width, $height, true, $quality);
        }
        $class = self::native[$data['type']];
        $imp = new $class();
        $data = $imp->getData($data);

        if (!empty($data['recode'])) {
            // re-encode the image as it was not possible to decode it
            $data = $this->getResizedRawData($data, $width, $height, true, $quality);
            $data = $imp->getData($data);
        }

        if (!empty($data['splitalpha'])) {
            // create 2 separate images: plain + mask
            $data['plain'] = $this->getResizedRawData($data, $width, $height, false, $quality);
            $data['plain'] = $imp->getData($data['plain']);
            $data['mask'] = $this->getAlphaChannelRawData($data);
            $data['mask'] = $imp->getData($data['alpha']);
        }

        // store data in cache
        self::$cache[$imgkey] = $data;

        return $data;
    }

    /**
     * Get the Image key used for caching
     *
     * @param string $image   Image file name or content
     * @param int    $width   Width in pixels
     * @param int    $height  Height in pixels
     * @param int    $quality Quality for JPEG files
     *
     * @return string
     */
    public function getKey($image, $width, $height, $quality)
    {
        return strtr(
            rtrim(
                base64_encode(
                    pack('H*', md5($image.$width.$height.$quality))
                ),
                '='
            ),
            '+/',
            '-_'
        );
    }

    /**
     * Get an imported image by key
     *
     * @param string $key Image key
     *
     * @return array Image raw data array
     */
    public function getImageByKey($key)
    {
        if (empty(self::$cache[$key])) {
            throw new ImageException('Unknown key');
        }
        return self::$cache[$key];
    }

    /**
     * Get the original image raw data
     *
     * @param string $image Image file name, URL or a '@' character followed by the image data string.
     *                      To link an image without embedding it on the document, set an asterisk character
     *                      before the URL (i.e.: '*http://www.example.com/image.jpg').
     *
     * @return array Image data array
     */
    protected function getRawData($image)
    {
        // default data to return
        $data = array(
            'key'      => '',            // image key
            'defprint' => false,         // default printing image when used as alternate
            'raw'      => '',            // raw image data
            'file'     => '',            // source file name or URL
            'exturl'   => false,         // true if the image is an exernal URL that should not be embedded
            'width'    => 0,             // image width in pixels
            'height'   => 0,             // image height in pixels
            'type'     => 0,             // image type constant: IMAGETYPE_XXX
            'native'   => false,         // true if the image is PNG or JPEG
            'mapto'    => IMAGETYPE_PNG, // type to convert to
            'bits'     => 8,             // number of bits per channel
            'channels' => 3,             // number of channels
            'colspace' => 'DeviceRGB',   // color space
            'icc'      => '',            // ICC profile
            'filter'   => 'FlateDecode', // decoding filter
            'parms'    => '',            // additional PDF decoding parameters
            'pal'      => '',            // colour palette
            'trns'     => array(),       // colour key masking
            'data'     => '',            // PDF image data
        );

        if (empty($image)) {
            return $data;
        }

        if ($image[0] === '@') { // image from string
            $data['raw'] = substr($image, 1);
        } else {
            if ($image[0] === '*') { // not-embedded external URL
                $data['exturl'] = true;
                $image = substr($image, 1);
            }
            $data['file'] = $image;
            $fobj = new File();
            $data['raw'] = $fobj->getFileData($image);
        }

        return $this->getMetaData($data);
    }

    /**
     * Get the image meta data
     *
     * @param string $data Image raw data
     *
     * @return array Image raw data array
     */
    protected function getMetaData($data)
    {
        if (empty($data['raw'])) {
            return $data;
        }
        $meta = getimagesizefromstring($data['raw']);
        $data['width'] = $meta[0];
        $data['height'] = $meta[1];
        $data['type'] = $meta[2];
        $data['native'] = isset(self::$native[$data['type']]);
        $data['mapto'] = (in_array($data['type'], self::$lossless) ? IMAGETYPE_PNG : IMAGETYPE_JPEG);
        if (isset($meta['bits'])) {
            $data['bits'] = intval($meta['bits']);
        }
        if (isset($meta['channels'])) {
            $data['channels'] = intval($meta['channels']);
        }
        if (isset(self::$colspacemap[$data['channels']])) {
            $data['colspace'] = self::$colspacemap[$data['channels']];
        }
        return $data;
    }

    /**
     * Get the resized image raw data
     * (always convert the image type to a native format: PNG or JPEG)
     *
     * @param string $data    Image raw data as returned by getImageRawData
     * @param int    $width   New width in pixels
     * @param int    $height  New height in pixels
     * @param bool   $alpha   If true save the alpha channel information, if false merge the alpha channel (PNG mode)
     * @param int    $quality Quality for JPEG files (0 = max compression; 100 = best quality, bigger file).
     *
     * @return array Image raw data array
     */
    protected function getResizedRawData($data, $width, $height, $alpha = true, $quality = 100)
    {
        $img = imagecreatefromstring($data['raw']);
        $newimg = imagecreatetruecolor($width, $height);
        imageinterlace($newimg, 0);
        imagealphablending($newimg, !$alpha);
        imagesavealpha($newimg, $alpha);
        imagecopyresampled($newimg, $img, 0, 0, 0, 0, $width, $height, $data['width'], $data['height']);
        ob_start();
        if ($data['mapto'] == IMAGETYPE_PNG) {
            if ((($tid = imagecolortransparent($img)) >= 0)
                && (($palsize = imagecolorstotal($img)) > 0)
                && ($tid < $palsize)
            ) {
                // set transparency for Indexed image
                $tcol = imagecolorsforindex($img, $tid);
                $tid = imagecolorallocate($newimg, $tcol['red'], $tcol['green'], $tcol['blue']);
                imagefill($newimg, 0, 0, $tid);
                imagecolortransparent($newimg, $tid);
            }
            imagepng($newimg, null, 9, PNG_ALL_FILTERS);
        } else {
            imagejpeg($newimg, null, $quality);
        }
        $data['raw'] = ob_get_clean();
        $data['exturl'] = false;
        $data['recoded'] = true;
        return $this->getMetaData($data);
    }

    /**
     * Extract the alpha channel as separate image to be used as a mask
     *
     * @param string $data Image raw data as returned by getImageRawData
     *
     * @return array Image raw data array
     */
    protected function getAlphaChannelRawData($data)
    {
        $img = imagecreatefromstring($data['raw']);
        $newimg = imagecreate($data['width'], $data['height']);
        imageinterlace($newimg, 0);
        // generate gray scale palette (0 -> 255)
        for ($col = 0; $col < 256; ++$col) {
            ImageColorAllocate($newimg, $col, $col, $col);
        }
        // extract alpha channel
        for ($xpx = 0; $xpx < $data['width']; ++$xpx) {
            for ($ypx = 0; $ypx < $data['height']; ++$ypx) {
                $colindex = imagecolorat($img, $xpx, $ypx);
                // get and correct gamma color
                $color = imagecolorsforindex($img, $colindex);
                // GD alpha is only 7 bit (0 -> 127); 2.2 is the gamma value
                $alpha = (pow(((127 - $color['alpha']) / 127), 2.2) * 255);
                imagesetpixel($newimg, $xpx, $ypx, $alpha);
            }
        }
        ob_start();
        imagepng($newimg, null, 9, PNG_ALL_FILTERS);
        $data['raw'] = ob_get_clean();
        $data['colspace'] = 'DeviceGray';
        $data['exturl'] = false;
        $data['recoded'] = true;
        return $this->getMetaData($data);
    }
}
