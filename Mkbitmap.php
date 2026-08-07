<?php
/* Mkbitmap, written by Peter Selinger (2001-2019). (https://potrace.sourceforge.net) 
 * PHP Port by Savoul Pelister (2025). (https://techlister.com/blog)
 * A PHP Port of Potrace,
 * 
 *  
 * Licensed under the GPL
 */

class MkBitmap {
    private $imagick;

    public function __construct($inputFile) {
        $this->imagick = new Imagick($inputFile);
        // Handle transparent PNG - flatten onto white background
        $this->imagick->setBackgroundColor(new ImagickPixel('white'));
        $this->imagick = $this->imagick->flattenImages();
    }

    public function process($options = []) {
        $defaults = [
            'scale'     => 2.0,    // mkbitmap default is 2x
            'filter'    => 4,
            'threshold' => 0.45,
            'blur'      => 0,
            'invert'    => false,
			'colorize'       => false,    // ← NEW: path to color image
			'colorize_strength' => 1.0,  // 0.5=lighter edges, 1.0=full color, 1.5=darker edges,
			'transparent' => false,
        ];
        $opts = array_merge($defaults, $options);

        // Step 1: Invert
        if ($opts['invert']) {
            $this->imagick->negateImage(false);
        }

        // Step 2: Convert to grayscale
        $this->imagick->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        // Step 3: High-pass filter (sharpening)
        if ($opts['filter'] > 0) {
            $this->highPassFilter($opts['filter']);
        }

        // Step 4: Blur (lowpass)
        if ($opts['blur'] > 0) {
            $this->lowpassIIR($opts['blur']);
        }

        // Step 5: Scale
        if ($opts['scale'] != 1.0) {
            $this->scale($opts['scale']);
        }

        // Step 6: Threshold to bitmap
         // Step 6: Colorize OR threshold to bilevel
		if ($opts['colorize'] !== false && file_exists($opts['colorize'])) {
			// Apply soft threshold first to clean up noise but keep grayscale
			// DO NOT use hard bilevel threshold - we need grayscale values for multiply
			$this->softThreshold($opts['threshold']);

			// Now colorize using the grayscale edge values
			$this->colorizeWithSource($opts['colorize'], $opts['colorize_strength']);
			
			
			if($opts['transparent']){
				// 2a. Force the image format to PNG to support alpha channels
				$this->imagick->setImageFormat('png');

				// 2b. Activate the alpha channel flag
				$this->imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

				// 2c. Define the background color you want to eliminate
				$targetColor = 'rgb(255, 255, 255)'; // White background

				// 2d. Set transparency values
				$alpha = 0.0; // 0.0 means fully transparent

				// 2e. Set a "fuzz" tolerance (handles JPEG compression artifacts near edges)
				// getQuantum() * 0.1 gives a 10% variance tolerance
				//$fuzz = Imagick::getQuantum() * 0.1; 
				$fuzz = 0;

				// 2f. Paint matching pixels transparent
				$this->imagick->transparentPaintImage($targetColor, $alpha, $fuzz, false);
				
			}
			else{
				// Output is now a color PNG, not bilevel
				$this->imagick->setImageType(Imagick::IMGTYPE_TRUECOLOR);			
			}

		} else {
			// Normal bilevel output
			$this->applyThreshold($opts['threshold']);
			$this->imagick->setImageType(Imagick::IMGTYPE_BILEVEL);
		}

        return $this;
    }
	
	/**
	 * Soft threshold: pixels above threshold → pushed toward white
	 *                 pixels below threshold → kept as-is (grayscale edges preserved)
	 * Unlike applyThreshold() this does NOT produce hard black/white bilevel.
	 */
	private function softThreshold($threshold) {
		$w = $this->imagick->getImageWidth();
		$h = $this->imagick->getImageHeight();

		$pixels = $this->imagick->exportImagePixels(0, 0, $w, $h, 'R', Imagick::PIXEL_FLOAT);

		$out = [];
		foreach ($pixels as $v) {
			if ($v >= $threshold) {
				$out[] = 1.0; // push to white (background)
			} else {
				// Keep edge pixels as-is, normalized within 0→threshold range
				$out[] = max(0.0, $v);
			}
		}

		$this->imagick->importImagePixels(0, 0, $w, $h, 'R', Imagick::PIXEL_FLOAT, $out);
	}

    /**
     * Export pixels safely, handling PIXEL_CHAR signed byte issue.
     * Values > 127 come back negative from PIXEL_CHAR, so we use PIXEL_FLOAT
     * and convert to 0-255 range ourselves.
     */
    private function exportPixels($w, $h) {
        // PIXEL_FLOAT returns 0.0-1.0 range, avoids signed byte issue entirely
        $raw = $this->imagick->exportImagePixels(0, 0, $w, $h, 'R', Imagick::PIXEL_FLOAT);
        $pixels = [];
        foreach ($raw as $v) {
            $pixels[] = (int) round($v * 255.0);
        }
        return $pixels;
    }

    /**
     * Import pixels back, normalizing 0-255 to 0.0-1.0 for PIXEL_FLOAT.
     */
    private function importPixels($w, $h, array $pixels) {
        $raw = [];
        foreach ($pixels as $v) {
            $raw[] = max(0.0, min(1.0, $v / 255.0));
        }
        $this->imagick->importImagePixels(0, 0, $w, $h, 'R', Imagick::PIXEL_FLOAT, $raw);
    }

    /**
     * Scale image using cubic interpolation.
     * Matches mkbitmap.c interpolate_cubic() exactly.
     */
    private function scale($factor) {
        if ($factor == 1.0) return;

        $w    = $this->imagick->getImageWidth();
        $h    = $this->imagick->getImageHeight();
        $newW = (int) round($w * $factor);
        $newH = (int) round($h * $factor);
        $s    = (int) $factor; // mkbitmap uses integer scale steps

        $src = $this->exportPixels($w, $h);
        $dst = array_fill(0, $newW * $newH, 0);

        $this->cubicInterpolate($src, $w, $h, $dst, $newW, $newH, $s);

        $this->imagick->clear();
        $this->imagick->newImage($newW, $newH, new ImagickPixel('gray'));
        $this->imagick->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $this->importPixels($newW, $newH, $dst);
    }

    /**
     * Cubic interpolation matching mkbitmap.c interpolate_cubic() exactly.
     *
     * Original C coefficients:
     *   poly[k][0] = (-t^3 + 2t^2 - t) / 2
     *   poly[k][1] = (3t^3 - 5t^2 + 2) / 2
     *   poly[k][2] = (-3t^3 + 4t^2 + t) / 2
     *   poly[k][3] = (t^3 - t^2) / 2
     */
    private function cubicInterpolate($src, $w, $h, &$dst, $newW, $newH, $s) {
        // Pre-calculate polynomial coefficients - FIXED to match C source exactly
        $poly = [];
        for ($k = 0; $k < $s; $k++) {
            $t          = $k / (float) $s;
            $t2         = $t * $t;
            $t3         = $t2 * $t;
            $poly[$k]   = [
                (-$t3 + 2 * $t2 - $t) / 2,         // poly[k][0]
                (3 * $t3 - 5 * $t2 + 2) / 2,        // poly[k][1]  ← center-left
                (-3 * $t3 + 4 * $t2 + $t) / 2,      // poly[k][2]  ← center-right
                ($t3 - $t2) / 2,                     // poly[k][3]
            ];
        }

        // Sliding window: window[k][i] = vertical interpolation for subpixel k, column offset i
        $window = array_fill(0, $s, array_fill(0, 4, 0.0));
        $p      = array_fill(0, 4, 0.0);

        $idxSrc = fn($x, $y) => $y * $w + $x;
        $idxDst = fn($x, $y) => $y * $newW + $x;

        for ($y = 0; $y < $h; $y++) {

            // Initialize window for first source column (x=0)
            $x = 0;
            for ($i = 0; $i < 4; $i++) {
                // Gather 4 vertical neighbors for column (x + i - 1)
                for ($j = 0; $j < 4; $j++) {
                    $p[$j] = $this->getPixelClamped($src, $w, $h, $x + $i - 1, $y + $j - 1);
                }
                // Compute vertical interpolation for each subpixel row k
                for ($k = 0; $k < $s; $k++) {
                    $window[$k][$i] = 0.0;
                    for ($j = 0; $j < 4; $j++) {
                        $window[$k][$i] += $poly[$k][$j] * $p[$j];
                    }
                }
            }

            while (true) {
                // Write output pixels for current source column x
                for ($l = 0; $l < $s; $l++) {        // horizontal subpixels
                    for ($k = 0; $k < $s; $k++) {    // vertical subpixels
                        $v = 0.0;
                        for ($i = 0; $i < 4; $i++) {
                            $v += $window[$k][$i] * $poly[$l][$i];
                        }
                        $dstIdx = $idxDst($x * $s + $l, $y * $s + $k);
                        if ($dstIdx < count($dst)) {
                            $dst[$dstIdx] = (int) round(max(0, min(255, $v)));
                        }
                    }
                }

                $x++;
                if ($x >= $w) break;

                // Slide window: shift columns 1,2,3 → 0,1,2
                for ($i = 0; $i < 3; $i++) {
                    for ($k = 0; $k < $s; $k++) {
                        $window[$k][$i] = $window[$k][$i + 1];
                    }
                }

                // Compute new column 3 = (x + 3 - 1) = x + 2
                for ($j = 0; $j < 4; $j++) {
                    $p[$j] = $this->getPixelClamped($src, $w, $h, $x + 2, $y + $j - 1);
                }
                for ($k = 0; $k < $s; $k++) {
                    $window[$k][3] = 0.0;
                    for ($j = 0; $j < 4; $j++) {
                        $window[$k][3] += $poly[$k][$j] * $p[$j];
                    }
                }
            }
        }
    }

    private function getPixelClamped($pixels, $w, $h, $x, $y) {
        $x = max(0, min($w - 1, $x));
        $y = max(0, min($h - 1, $y));
        return $pixels[$y * $w + $x];
    }

    /**
     * High-pass filter: result = original - lowpass(original) + 128
     * Matches mkbitmap.c highpass() exactly.
     */
    private function highPassFilter($radius) {
        if ($radius <= 0) return;

        $w = $this->imagick->getImageWidth();
        $h = $this->imagick->getImageHeight();

        $orig = $this->exportPixels($w, $h);
        $blur = $orig; // copy

        // Apply IIR lowpass to get blurred version
        $this->applyIIRLowpass($blur, $w, $h, $radius);

        // Highpass = original - blurred + WHITE/2  (WHITE=255, WHITE/2=127.5→128)
        $out = [];
        for ($i = 0, $len = count($orig); $i < $len; $i++) {
            $out[] = max(0, min(255, (int) round($orig[$i] - $blur[$i] + 128)));
        }

        $this->importPixels($w, $h, $out);
    }

    /**
     * IIR lowpass filter matching mkbitmap.c lowpass_iir() EXACTLY.
     *
     * Original C source has ONLY 2 passes per axis (forward + backward).
     * There is NO third mop-up pass in the C source.
     *
     * f and g are reset to 0 at the start of each row/column.
     */
    private function applyIIRLowpass(array &$pixels, $w, $h, $lambda) {
        if ($lambda < 0.01) return;

        $B = 1.0 + 2.0 / ($lambda * $lambda);
        $c = $B - sqrt($B * $B - 1.0);
        $d = 1.0 - $c;

        // === Horizontal pass ===
        for ($y = 0; $y < $h; $y++) {
            $f = $g = 0.0;

            // Forward pass (left → right)
            for ($x = 0; $x < $w; $x++) {
                $i       = $y * $w + $x;
                $f       = $f * $c + $pixels[$i] * $d;
                $g       = $g * $c + $f * $d;
                $pixels[$i] = $g;                    // write back in-place
            }

            // Backward pass (right → left) reads already-written values
            for ($x = $w - 1; $x >= 0; $x--) {
                $i       = $y * $w + $x;
                $f       = $f * $c + $pixels[$i] * $d;
                $g       = $g * $c + $f * $d;
                $pixels[$i] = $g;
            }
            // NO mop-up pass - C source stops here for horizontal
        }

        // === Vertical pass ===
        for ($x = 0; $x < $w; $x++) {
            $f = $g = 0.0;

            // Forward pass (top → bottom)
            for ($y = 0; $y < $h; $y++) {
                $i       = $y * $w + $x;
                $f       = $f * $c + $pixels[$i] * $d;
                $g       = $g * $c + $f * $d;
                $pixels[$i] = $g;
            }

            // Backward pass (bottom → top)
            for ($y = $h - 1; $y >= 0; $y--) {
                $i       = $y * $w + $x;
                $f       = $f * $c + $pixels[$i] * $d;
                $g       = $g * $c + $f * $d;
                $pixels[$i] = $g;
            }
            // NO mop-up pass - C source stops here for vertical
        }
    }

    /**
     * Standalone lowpass IIR (used when blur option is set directly).
     * Calls applyIIRLowpass on the current image pixels.
     */
    private function lowpassIIR($radius) {
        if ($radius < 0.01) return;

        $w      = $this->imagick->getImageWidth();
        $h      = $this->imagick->getImageHeight();
        $pixels = $this->exportPixels($w, $h);

        $this->applyIIRLowpass($pixels, $w, $h, $radius);

        $this->importPixels($w, $h, $pixels);
    }
	
	
	private function colorizeWithSource($colorImagePath, $strength) {
		$w = $this->imagick->getImageWidth();
		$h = $this->imagick->getImageHeight();

		// --- Prepare edge mask ---
		$edgeMask = clone $this->imagick;
		$edgeMask->transformImageColorspace(Imagick::COLORSPACE_SRGB);
		$edgeMask->setImageType(Imagick::IMGTYPE_TRUECOLOR);

		$edgePixels = $edgeMask->exportImagePixels(0, 0, $w, $h, 'R', Imagick::PIXEL_FLOAT);
		$edgeMask->destroy();

		// --- Load color source ---
		$colorSrc = new Imagick($colorImagePath);
		$colorSrc->setBackgroundColor(new ImagickPixel('white'));
		$colorSrc = $colorSrc->flattenImages();
		$colorSrc->transformImageColorspace(Imagick::COLORSPACE_SRGB);
		$colorSrc->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1);

		$colorPixels = $colorSrc->exportImagePixels(0, 0, $w, $h, 'RGB', Imagick::PIXEL_FLOAT);
		$colorSrc->destroy();

		$totalPixels     = $w * $h;
		$output          = [];
		$threshold       = 0.85;  // above this = background = white

		for ($i = 0; $i < $totalPixels; $i++) {
			$edgeVal = $edgePixels[$i];   // 0.0=black(strong edge) → 1.0=white(background)
			$cr      = $colorPixels[$i * 3];
			$cg      = $colorPixels[$i * 3 + 1];
			$cb      = $colorPixels[$i * 3 + 2];

			if ($edgeVal >= $threshold) {
				// Background → pure white
				$output[] = 1.0;
				$output[] = 1.0;
				$output[] = 1.0;
			} else {
				// Edge pixel:
				// edgeVal=0.0 → pure black edge  → output full source color (no darkening)
				// edgeVal=0.5 → mid edge         → output source color slightly darkened
				// edgeVal=0.84 → near background → output source color barely darkened
				//
				// We want:
				//   - Strong edges (edgeVal near 0) = FULL color, not darkened
				//   - Weak edges (edgeVal near threshold) = slightly lighter color
				//
				// So we INVERT the logic: darkness = (1 - edgeVal)
				// Then blend: output = color × 1.0 at strong edges, slightly less at weak edges

				// Remap edgeVal within 0→threshold to 0→1
				$normalized = $edgeVal / $threshold;  // 0.0=strong edge, 1.0=weak edge

				// Darkness factor: strong edge=fully colored, weak edge=nearly white
				// color stays near original, only blended toward white at weak edges
				//$colorFactor = 1.0 - $normalized;     // 1.0=strong edge, 0.0=weak edge (background)
				//$strength = $this->opts['colorize_strength'];
				$colorFactor = pow(1.0 - ($edgeVal / $threshold), $strength);

				// Blend between full source color and white based on edge strength
				// At strong edge (colorFactor=1.0): output = source color fully
				// At weak edge  (colorFactor=0.0): output = white
				$r = $cr * $colorFactor + 1.0 * (1.0 - $colorFactor);
				$g = $cg * $colorFactor + 1.0 * (1.0 - $colorFactor);
				$b = $cb * $colorFactor + 1.0 * (1.0 - $colorFactor);

				$output[] = max(0.0, min(1.0, $r));
				$output[] = max(0.0, min(1.0, $g));
				$output[] = max(0.0, min(1.0, $b));
			}
		}

		// --- Build output image ---
		$result = new Imagick();
		$result->newImage($w, $h, new ImagickPixel('white'));
		$result->setImageFormat('png');
		$result->setImageColorspace(Imagick::COLORSPACE_SRGB);
		$result->importImagePixels(0, 0, $w, $h, 'RGB', Imagick::PIXEL_FLOAT, $output);

		$this->imagick->clear();
		$this->imagick = $result;
	}

    /**
     * Threshold: pixels above threshold*WHITE become WHITE, others become BLACK.
     * Matches mkbitmap.c threshold() exactly.
     */
    private function applyThreshold($threshold) {
        $quantum = $this->imagick->getQuantumRange()['quantumRangeLong'];
        $this->imagick->thresholdImage($quantum * $threshold);
    }

    public function save($outputFile, $format = 'pbm') {
        $this->imagick->setImageFormat($format);
        $this->imagick->writeImage($outputFile);
    }

    public function getImagick() {
        return $this->imagick;
    }

    public function __destruct() {
        if ($this->imagick) {
            $this->imagick->clear();
            $this->imagick->destroy();
        }
    }
}
