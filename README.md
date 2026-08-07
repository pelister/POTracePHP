# POTracePHP
PHP port of Potrace and MKBitmap,  tool for tracing bitmap images (like scans or logos) and converting them into smooth, scalable vector graphics (e.g., SVG or EPS).  Mkbitmap is its companion program used to pre-process images before Potrace traces them.

This is complete port of Potrace with all the features of Potrace. (check the source for example usage)

A primary use case is converting scanned line art, such as cartoons or handwritten text, into high-quality vector output. The workflow typically involves using Mkbitmap to clean up and prepare an image, then passing it to Potrace for Vectorization.

# The Highpass Filter in Mkbitmap

The highpass filter in  Mkbitmap is a powerful feature designed to ensure foreground features like lines and text are preserved while compensating for uneven backgrounds.

# Usage
```php
include('Potrace.php');

$pot = new POtracePHP();

$pot->setParameter([
	'turnpolicy' => "minority",
	'alphamax' => 1, ///default 1, range 0.0 to 1.33
	'opttolerance' => 0.2, //default 0.2, range 0.0 to 4.0
	'flat' => true,
	//'group' => true,
	'turdsize' => 0, //default 2
	'coord' => 'relative', //relative or absolute
	//'stroke' => true, // false will fill the close paths with 'fillcolor'
	//'width' => 400,
	//'height' => 400 , //width and height of SVG
	//'margins' => array('left' => 50, 'right' => 50, 'top' => 50, 'bottom' => 50),
	'bg' => true, //adds a fillable box <rect> as BG.
	'unit' => 10, //default 10 (Potrace default is 10)
	'fillcolor' => '#ffffff', //fill color
	'linecolor' => '#000000' //sets line color.
]); 
 
$pot->loadImageFromFile('art.jpg', 128);
 
$pot->process();

$svg = $pot->getSVG(1);
$pdf = $pot->getPDF(1);
$eps = $pot->getEPS(1);
```

# Purpose: 
It’s specifically used to “flatten” an image, making it easier to trace by removing gradients or shadows that can confuse the thresholding process, which is the step that actually converts the image to black and white.

# How it works:
The filter operates by subtracting a blurred version of the image from the original.

# Typical Use:
Generate vector logos, maps, or other line art from scanned images. Prepare scanned line art with uneven backgrounds before tracing with Potrace. |

In a typical workflow, you would use Mkbitmap to pre-process your image (using its filtering, scaling, and thresholding capabilities) and then pipe the resulting PBM file directly to  Potrace for Vectorization.

This is PHP port of Potrace and MKBitmap, simply include the Potrace PHP Class and start using Potrace.

This is complete PHP Port of Potrace and works like Potrace  with additional features added.
