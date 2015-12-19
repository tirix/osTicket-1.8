<?php
	require_once('staff.inc.php');

	// Get Ticket List
	//$output = 'DBG';
	//$output = 'PDF';
	if(isset($_GET['output'])) $output = $_GET['output'];
	if(isset($_GET['scale'])) $scale = $_GET['scale']; else $scale = 1;
	if(isset($_GET['skip'])) $skip = $_GET['skip']; else $skip = 0;
	if(isset($_GET['format'])) $format = $_GET['format']; else $format = 'L4743';
	if(isset($_GET['tids'])) $tids = explode('-', $_GET['tids']); else $tids = array();
	require_once('sticker-page.inc.php');
	
	setlocale(LC_NUMERIC, 'en_US.utf-8'); // we want dots when printing floats!
	define(PX_PER_MM, 3.780718); // 3.543307
	define(FONT_PER_LINE, .75); // Font size is 3/4 of line height
	define(STICKER_FONT_AVG_WIDTH_PER_SIZE, 2.3); // 
	define(STICKER_FONT, "Arial");
	define(OVERFLOW_NONE, 0);
	define(OVERFLOW_BREAK, 1);
	define(OVERFLOW_HIDE, 2);
	require_once(INCLUDE_DIR.'class.export.php');       // For paper sizes
	include('../include/mpdf/mpdf.php');
	$formats = array(
		'L4743' => array(
			'page-width' => 210,
			'page-height' => 296.7,
			'margin-left' => 4.54,
			'margin-top' => 21.18,
			'sticker-width' => 99.1,
			'sticker-height' => 42.3,
			'space-x' => 2.8,
			'space-y' => 0.53,
			'text-margin-x' => 10.0,
			'text-margin-y' => 8.0,
			'text-line-height' => 7.0,
			'logo-x' => 50,
			'logo-y' => -3,
		),
	);
	if(!isset($formats[$format])) die("Unknown page format $format");
	$config = $formats[$format];

	$pageWidth = $config['page-width'] * $scale;
	$pageHeight = $config['page-height'] * $scale;
	$count = count($tids);

	function unicode_unescape($str) {
		$str = preg_replace('/\\\\"/', '"', $str);
		return $str = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
		    return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
		}, $str);
	}
	
	function getFormValue($formData) {
		$pattern = '/\\{\\"(.*)\\"\\:\\"(.*)\\"\\}/';
		if(preg_match($pattern, $formData, $matches))
			return $matches[2];
		else
			return '';
	}
		
	function getMultiFormValue($formData) {
		$pattern = '/\\"((?:[^"\\\\]|\\\\.)*)\\"\\:\\"((?:[^"\\\\]|\\\\.)*)\\"/';
		if(preg_match_all($pattern, $formData, $matches)) {
			$values = array();
			foreach($matches[2] as $match) array_push($values, unicode_unescape($match)); //
			return $values;
		}
		else
			return aray();
	}
		
	function buildAddress($ticket) {
		$address = array();
		array_push($address, $ticket['firstname'].' '.strtoupper($ticket['name']));
		$address = array_merge($address, explode("\n", $ticket['address']));
		array_push($address, $ticket['zip'].' '.strtoupper($ticket['city']));
		array_push($address, strtoupper(getFormValue($ticket['country'])));
		return $address;
	}
	
	function buildInfo($ticket) {
		$info = array();
		array_push($info, "Ticket #".$ticket['number']);
		array_push($info, $ticket['firstname'].' '.strtoupper($ticket['name']));
		//array_push($info, $ticket['email']); // email shall not be on the second sticker
		array_push($info, getFormValue($ticket['game']));
		array_push($info, $ticket['count1'].' '.$ticket['desc1']);
		if(isset($ticket['count2'])) array_push($info, $ticket['count2'].' '.$ticket['desc2']);
		if(isset($ticket['count3'])) array_push($info, $ticket['count3'].' '.$ticket['desc3']);
		if(isset($ticket['goodies'])) $info = array_merge($info, getMultiFormValue($ticket['goodies']));
		return $info;
	}
	
	function nextSticker() {
		global $x, $y, $config, $scale;

		$x += $config['sticker-width'] + $config['space-x'];
		if(($x+$config['sticker-width']) > $config['page-width'] ) {
			$x = $config['margin-left'];
			$y += $config['sticker-height'] + $config['space-y'];
		}
		if(($y+$config['sticker-height']) > $config['page-height'] ) {
			$y = $config['margin-top'];
		?>
	</g>
</svg>
<svg
   width="<?php echo $config['page-width'] * $scale ?>mm"
   height="<?php echo $config['page-height'] * $scale ?>mm"
   >
	<g transform="scale(<?php echo $scale ?>)">
		<?php
		}
	}
	
	function truncate_text($text, $fontSize, $width) {
		//$bbox = imagettfbbox($fontSize, 0, STICKER_FONT, $text);
		//$line_width = $bbox[2];
		//if($line_width == 0) return $text;
		//$line_break = strlen($text)*$line_width/$width;
		$line_break = STICKER_FONT_AVG_WIDTH_PER_SIZE*$width/$fontSize; // heuristics :-)
		return substr($text, 0, $line_break);
	}

	function needs_break($lines, $fontSize, $width) {
		$line_break = STICKER_FONT_AVG_WIDTH_PER_SIZE*$width/$fontSize; // heuristics :-)
		$breakCount = 0;
		foreach($lines as $line)
			$breakCount += (int)(strlen($line) / $line_break);
		return $breakCount;
	}
	
	function break_lines($lines, $fontSize, $width) {
		$line_break = STICKER_FONT_AVG_WIDTH_PER_SIZE*$width/$fontSize; // heuristics :-)
		$broken_lines = array();
		foreach($lines as $line) {
			while(strlen($line) > $line_break) {
				array_push($broken_lines, substr($line, 0, $line_break));
				$line = substr($line, $line_break);
			}
			array_push($broken_lines, substr($line, 0, $line_break));
		}
		return $broken_lines;
	}
	
	
	function textLines($lines, $fontStyle, $overflow, $width) {
		global $x, $y, $config;

		$available_height = $config['sticker-height'] - 2 * $config['text-margin-y'];
		$line_height = min($available_height / (count($lines) - (1 - FONT_PER_LINE)), $config['text-line-height']);
		$fontSize = $line_height * FONT_PER_LINE;
		if($overflow == OVERFLOW_BREAK && needs_break($lines, $fontSize, $width)) {
			$breakCount = needs_break($lines, $fontSize, $width);
			$line_height = min($available_height / (count($lines) + $breakCount - 1 - (1 - FONT_PER_LINE)), $config['text-line-height']);
			$fontSize = $line_height * FONT_PER_LINE;
			$lines = break_lines($lines, $fontSize, $width);
			$line_height = min($available_height / (count($lines) - (1 - FONT_PER_LINE)), $config['text-line-height']);
			$fontSize = $line_height * FONT_PER_LINE;
		}
		$fontStyle .= 'font-size:'.$fontSize.'mm;';

		$line_y = $line_height * FONT_PER_LINE;
		foreach($lines as $line)
		{
			if($overflow == OVERFLOW_HIDE) $line = truncate_text($line, $fontSize, $width)
?>
       <text
       	x="<?php echo $x+$config['text-margin-x'] ?>mm"
       	y="<?php echo $y+$config['text-margin-y']+$line_y ?>mm"
         style="<?php echo $fontStyle ?>"
         ><?php echo $line ?></text>
<?php 
			$line_y += $line_height;
		}
	}

	function stickerShape($stickerStyle) {
		global $x, $y, $config;
		?>
		     <rect
		       style="<?php echo $stickerStyle ?>"
		       width="<?php echo $config['sticker-width']; ?>mm"
		       height="<?php echo $config['sticker-height']; ?>mm"
		       x="<?php echo $x; ?>mm"
		       y="<?php echo $y; ?>mm"
		       ry="2.5mm" />
		<?php  
	}	
	
	function matagotLogo() {
		global $x, $y, $config;
		?>
			<g transform="translate(<?php echo ($x+$config['text-margin-x']+$config['logo-x'])*PX_PER_MM ?>,<?php echo ($y+$config['text-margin-y']+$config['logo-y'])*PX_PER_MM ?>) scale(.7)">
		    	<path
		    		style="fill:#505060"
		    		d="m 128.8171,172.65773 c -2.91051,-0.90858 -5.62848,-2.20056 -6.03993,-2.87107 -0.41145,-0.67051 -8.04043,-2.96926 -16.9533,-5.10833 -12.545372,-3.01087 -16.433892,-3.61367 -17.217782,-2.66914 -1.32597,1.5977 -13.99716,-1.25979 -13.99716,-3.15651 0,-1.33816 -0.3394,-1.44646 -19.84948,-6.33336 -9.233076,-2.31272 -12.686836,-2.81334 -13.686326,-1.98383 -0.99062,0.82214 -2.86807,0.67985 -7.24903,-0.54939 l -5.91218,-1.65887 -0.64783,-14.16073 c -0.35631,-7.7884 -0.64714,-21.36073 -0.6463,-30.16073 l 0.002,-16.000001 9.74481,-5.9601 c 5.35964,-3.27805 12.40325,-7.62902 15.65247,-9.66881 l 5.907656,-3.70873 -3.40766,-2.14795 c -3.207296,-2.02167 -4.230646,-2.11139 -17.407626,-1.52609 -13.92724,0.61863 -14.02855,0.60728 -19.49313,-2.18323 -9.8191305,-5.01418 -14.5200105,-13.95778 -15.6844905,-29.84037 -0.8262,-11.26856 1.22586,-17.97535 7.469,-24.4111104 4.5798105,-4.72112 9.4896405,-7.03448 14.9586205,-7.04803 2.78651,-0.007 6.25,3.31495 6.25,5.99442 0,2.5963104 -3.43186,6.0000004 -6.04966,6.0000004 -3.34251,0 -7.80851,2.82504 -9.43341,5.96727 -2.09088,4.04331 -1.92607,17.18973 0.28745,22.92967 2.29127,5.94154 5.91922,8.50286 11.94168,8.43078 2.61467,-0.0313 6.10394,-0.60862 7.75394,-1.28295 l 3,-1.22606 -3.82414,-1.54588 c -3.10537,-1.25532 -3.72512,-1.94313 -3.29729,-3.65936 0.28977,-1.16241 1.07624,-5.44958 1.74771,-9.52705 1.08139,-6.56669 1.69551,-7.90896 5.37587,-11.75 4.13009,-4.3104 4.19654,-4.33616 11.07643,-4.29287 3.806776,0.024 8.721416,0.42218 10.921416,0.88494 2.2,0.46277 9.4,1.99479 16,3.4045 7.5286,1.60805 14.98094,2.54 20,2.50109 9.019412,-0.0699 15.079922,1.26104 18.696712,4.10602 2.86782,2.25582 24.22035,6.05665 34.07502,6.06548 12.17507,0.0109 22.25498,4.91515 25.2621,12.29093 2.80371,6.87689 3.01583,16.09862 0.58593,25.47281 -1.90452,7.34733 -2.11976,10.90958 -2.11976,35.082511 l 0,26.90482 -18.18673,20.12167 c -10.0027,11.06693 -18.6652,20.07756 -19.25,20.02364 -0.5848,-0.0539 -3.44459,-0.84142 -6.3551,-1.75 z m 26.12912,-21.79664 14.66271,-16.14468 0,-26.09021 c 0,-24.862361 0.11765,-26.496211 2.5,-34.717191 2.75169,-9.4955 3.06831,-14.65485 1.38192,-22.51824 -1.23488,-5.75805 -4.51093,-9.51208 -10.76012,-12.33008 -3.31859,-1.49647 -3.99257,-1.50686 -7.32858,-0.11299 -6.21373,2.59626 -10.73001,10.28525 -12.33748,21.00462 -0.54325,3.62267 -1.04224,4.51096 -2.32377,4.13669 -0.89758,-0.26213 -3.27115,-0.76661 -5.27459,-1.12107 -3.61601,-0.63975 -3.63874,-0.67484 -3.11198,-4.80332 1.33882,-10.49301 6.42877,-18.7728 12.65007,-20.57776 l 3.10453,-0.90071 -3.91667,-0.0902 c -2.15416,-0.0496 -3.68362,0.14286 -3.39878,0.42769 0.28483,0.28483 -0.97861,2.10462 -2.80765,4.04397 -3.37323,3.57667 -6.3769,11.40491 -6.3769,16.61965 0,1.55018 -0.38071,2.8185 -0.84603,2.8185 -0.46532,0 -9.57782,-1.57315 -20.25,-3.49588 -10.672192,-1.92274 -19.795562,-3.49774 -20.274162,-3.5 -0.47861,-0.002 -0.67784,-2.66343 -0.44273,-5.91369 0.97833,-13.52538 13.436882,-21.86261 23.337132,-15.61716 1.11331,0.70232 4.48831,1.53626 7.5,1.85319 l 5.47579,0.57625 -5.01339,-1.20136 c -2.75736,-0.66074 -6.13236,-2.00001 -7.5,-2.97614 -3.85465,-2.75121 -9.75117,-4.01019 -18.486612,-3.94712 -7.1606,0.0517 -12.47991,-0.81066 -36,-5.83629 -2.2,-0.47008 -6.59411,-0.8743 -9.764686,-0.89825 -5.14305,-0.0389 -6.14826,0.30797 -9.32176,3.21627 -2.93195,2.68693 -3.81207,4.47629 -5.00804,10.1818 -0.79804,3.80709 -1.29432,7.39196 -1.10285,7.96637 0.19147,0.57441 0.91188,-1.8699 1.6009,-5.4318 2.16585,-11.19637 5.67863,-14.9219 14.09644,-14.95022 l 5.499996,-0.0185 -2.999996,1.85309 c -5.01653,3.09868 -6.34228,5.3582 -8.31674,14.17452 -1.04268,4.6557 -2.29796,8.40736 -2.78952,8.337 -0.49156,-0.0703 -2.69374,-0.3908 -4.89374,-0.71211 -3.91635,-0.57199 -3.87453,-0.52329 2,2.32867 6.20524,3.01252 9.90398,4.0289 5.5,1.51136 -2.17263,-1.24199 -2.23811,-1.41828 -0.5,-1.34625 2.84039,0.1177 8.3027,1.58265 6.5,1.74325 -1.25938,0.11219 -1.27587,0.27554 -0.10279,1.01831 0.96871,0.61337 0.35396,0.88469 -2.00444,0.88469 -1.87091,0 -7.24261,0.7628 -11.93711,1.69512 -10.60893,2.10691 -15.65747,1.11859 -19.29051,-3.77638 -3.55105,-4.7845 -4.66515,-9.11503 -4.66515,-18.13356 0,-12.12592 2.84781,-16.19249 13.49028,-19.2636 3.26035,-0.9408404 4.3706,-3.5793604 2.46985,-5.8696204 -2.30793,-2.78089 -10.29913,-0.84924 -15.54638,3.75791 -6.1478905,5.3979204 -7.8607505,9.9327504 -7.8526805,20.7901304 0.0117,15.77157 4.84568,26.66511 13.9389305,31.41193 3.5946,1.87644 5.40138,2.05745 17.82717,1.78599 14.96954,-0.32704 19.046066,0.44157 22.641736,4.26899 l 2.26302,2.40887 -4.86597,3.00598 c -2.67628,1.65328 -9.815956,6.07885 -15.865956,9.8346 l -11,6.82861 -0.27822,13.727521 c -0.18897,9.32383 0.0678,13.72751 0.80038,13.72751 0.72351,0 1.02536,-4.19735 0.9169,-12.75 l -0.1617,-12.750001 2.86132,0.35058 c 1.57373,0.19282 3.19882,0.55688 3.61132,0.80902 0.4125,0.25214 0.75,13.256881 0.75,28.899421 0,16.57029 0.3826,28.44098 0.91667,28.44098 0.50416,0 1.10054,-0.55162 1.32527,-1.22582 0.57303,-1.71908 33.454236,5.98283 35.660646,8.35295 1.46554,1.57427 1.57432,-0.62223 1.31775,-26.6068 l -0.27965,-28.32272 3.92887,0.99725 3.92887,0.99725 0.60914,15.15395 c 0.33502,8.33467 0.71788,21.11296 0.85078,28.3962 0.13291,7.28324 0.48915,13.24574 0.79165,13.25 0.3025,0.004 0.98304,-0.4253 1.5123,-0.95457 0.61641,-0.6164 6.14846,0.28935 15.390432,2.51986 7.93546,1.9152 14.81777,3.35228 15.29401,3.19354 0.47625,-0.15875 -6.06597,-1.99826 -14.53826,-4.08779 l -15.404162,-3.79916 -0.70646,-26.54444 c -0.38855,-14.59944 -0.55544,-26.69546 -0.37085,-26.88004 0.18458,-0.18459 8.42152,1.60554 18.304302,3.97806 l 17.96869,4.31366 0,26.63153 c 0,25.93762 -0.0521,26.63366 -2,26.71326 -1.42441,0.0582 -1.56831,0.23756 -0.5,0.62321 0.825,0.29782 2.175,1.3512 3,2.34084 0.825,0.98965 3.525,2.21782 6,2.72928 5.31542,1.09844 3.1489,2.91573 24.33729,-20.41418 z m -26.33729,18.75032 -2.5,-0.695 -0.2659,-27.75362 -0.26591,-27.75361 3.76591,0.67963 c 2.07124,0.3738 4.1034,0.87209 4.5159,1.10732 0.4125,0.23522 0.75,12.77612 0.75,27.86866 0,30.20077 0.42236,28.33204 -6,26.54662 z m -71.250002,-19.42283 -16.749996,-4.08202 0,-26.86778 c 0,-25.124731 0.11353,-26.833211 1.75,-26.334821 0.9625,0.29313 8.43478,2.09574 16.605076,4.0058 8.17029,1.91006 15.06671,3.684471 15.32539,3.943141 0.9189,0.9189 1.63244,53.65329 0.72438,53.53529 -0.49767,-0.0647 -8.44235,-1.95449 -17.65485,-4.19961 z m 61.250002,-40.65266 c -8.525,-2.08127 -31.362502,-7.53241 -50.750002,-12.113651 -19.387496,-4.58124 -35.228666,-8.57409 -35.202596,-8.87301 0.0979,-1.12306 27.925556,-17.89625 28.404686,-17.12102 0.68527,1.10881 -1.54019,2.89814 -11.359766,9.13355 -4.62423,2.93637 -7.99923,5.44717 -7.5,5.57954 0.49922,0.13238 21.578106,5.08268 46.841956,11.00068 L 134.97749,107.902 148.18575,94.203879 c 11.40219,-11.82505 13.63317,-13.69811 16.31572,-13.69811 1.7091,0 3.10746,0.2614 3.10746,0.58089 0,1.46736 -30.58217,32.408351 -31.95136,32.326241 -0.85175,-0.0511 -8.52364,-1.79572 -17.04864,-3.87698 z m 13,-11.842091 c 0,-0.44657 1.37653,-1.82965 3.05895,-3.07352 2.33454,-1.726 3.37913,-1.99586 4.41097,-1.1395 1.5703,1.30323 2.03851,0.72029 2.99194,-3.72504 0.59753,-2.786 1.12363,-3.25 3.68498,-3.25 2.6341,0 2.90713,0.26645 2.30561,2.25 -0.98126,3.23579 -4.45101,6.7941 -8.17033,8.37888 -3.547,1.51136 -8.28212,1.83105 -8.28212,0.55918 z m -28.68143,-3.81175 c -3.642722,-3.86241 -4.023362,-6.87631 -0.86844,-6.87631 1.32982,0 2.18243,1.0022 2.76489,3.25 0.89608,3.45808 2.17734,4.13877 3.48529,1.85162 0.6293,-1.10044 1.63419,-0.6743 4.7163,2 l 3.91661,3.39838 -5.29853,0 c -4.7945,0 -5.62364,-0.34471 -8.71612,-3.62369 z m 58.58209,-15.75057 c -1.97963,-0.70756 -7.90803,-3.58867 -13.17422,-6.40246 l -9.57488,-5.11599 -43.747392,-8.74644 c -24.06106,-4.81055 -43.239276,-8.91581 -42.618256,-9.12282 0.621016,-0.20701 7.017356,0.81088 14.214086,2.26197 7.19673,1.45109 13.62834,2.41775 14.29248,2.14812 0.69472,-0.28204 0.49104,-0.52375 -0.47961,-0.56917 -3.71347,-0.17377 0.71879,-15.45119 6.13429,-21.14406 3.72155,-3.91217 3.78593,-3.93437 11.148,-3.8447 6.666032,0.0812 7.055632,0.18942 3.904842,1.08476 -8.162272,2.31942 -13.446102,9.26085 -14.614102,19.19872 -0.54211,4.61248 -0.9978,5.57148 -2.76743,5.82402 -1.16516,0.16627 11.38153,2.91769 27.881532,6.11427 l 30,5.81197 12.25,6.49383 c 6.7375,3.57161 12.25,6.69372 12.25,6.93804 0,0.6851 -1.18516,0.46895 -5.09934,-0.93006 z M 59.937568,47.654949 c -7.794256,-1.45705 -14.387236,-2.87418 -14.651086,-3.14918 -0.26385,-0.275 0.14963,-3.875 0.91883,-8 1.19022,-6.38273 1.91814,-7.98415 4.8864,-10.75 4.328776,-4.03359 6.628406,-4.10604 23.436086,-0.73833 6.83037,1.36858 12.62024,2.48833 12.86636,2.48833 0.24612,0 -1.15447,1.77319 -3.11243,3.94041 -3.46061,3.83048 -6.6728,11.88941 -6.6728,16.74109 0,2.83086 0.38079,2.84233 -17.67136,-0.53232 z M 36.525602,148.15423 c -0.22917,-0.19335 -1.87917,-0.84494 -3.66667,-1.44798 -3.66481,-1.23638 -4.46257,-0.62342 -1,0.76836 2.21527,0.89043 5.48007,1.36589 4.66667,0.67962 z M 137.87796,35.849829 c -0.67703,-0.27392 -2.02703,-0.29059 -3,-0.0371 -0.97297,0.25355 -0.41903,0.47767 1.23097,0.49805 1.65,0.0204 2.44606,-0.18707 1.76903,-0.46099 z m 13.46014,0.0518 c -0.3323,-0.33229 -1.1948,-0.36782 -1.91667,-0.0789 -0.79773,0.31923 -0.56078,0.55619 0.60417,0.60417 1.05416,0.0434 1.64479,-0.19293 1.3125,-0.52522 z m -19.46014,-1.05177 c -0.67703,-0.27392 -2.02703,-0.29059 -3,-0.0371 -0.97297,0.25355 -0.41903,0.47767 1.23097,0.49805 1.65,0.0204 2.44606,-0.18707 1.76903,-0.46099 z"
		    	/>
			</g>			
		<?php
	}
	
	ob_start();
?>
<svg
   width="<?php echo $pageWidth ?>mm"
   height="<?php echo $pageHeight ?>mm"
   >
<g transform="scale(<?php echo $scale ?>)">
 <?php 
	//$fontStyle = "font-size:20px;font-style:normal;font-variant:normal;font-weight:normal;font-stretch:normal;text-align:start;line-height:125%;letter-spacing:0px;word-spacing:0px;text-anchor:start;fill:#000000;fill-opacity:1;stroke:none;font-family:Arial";
	$fontStyle = "font-family:".FONT.";";
	//$stickerStype = "fill:#e1e2ff;fill-opacity:1;stroke:#030000;stroke-width:0.2;stroke-linecap:round;stroke-linejoin:miter;stroke-miterlimit:4;stroke-opacity:1;stroke-dasharray:none;stroke-dashoffset:0";
	$stickerStyle = "fill:none;stroke:#4340f0;stroke-width:0.2;";
	$x = $config['margin-left'] - $config['sticker-width'] - $config['space-x'];
	$y = $config['margin-top'];
	for($i = 0; $i < $skip; $i++) nextSticker();
	//for($i = 0; $i < $count;$i++)
	foreach($tickets as $ticket)
	{
		nextSticker();
		stickerShape($stickerStyle);
		$address = buildAddress($ticket);
		textLines($address, $fontStyle, OVERFLOW_BREAK, $config['sticker-width']-2*$config['text-margin-x']);
      	nextSticker();
		stickerShape($stickerStyle);
		$info = buildInfo($ticket);
		textLines($info, $fontStyle."fill:#808090;", OVERFLOW_HIDE, $config['logo-x']);
		matagotLogo();
	}
?>
</g>
</svg>

<?php
	$svg = ob_get_contents();
	ob_end_clean();
	if($output == 'PDF' || $output == 'DBG') {
		$dest = $output == 'DBG' ? 'S' : 'I';
		$mpdf=new mPDF('',array($pageWidth,$pageHeight), 0, '', 0, 0, 0, 0, 0, 0);
		$mpdf->debug = $output == 'DBG';
		$mpdf->showImageErrors = true;
		$mpdf->compress = false;
		$mpdf->SetTitle('Matagot Address Stickers');
		$mpdf->WriteHTML($svg);
		$content = $mpdf->Output('stickers-150320-1237.pdf', $dest);
		if($output == 'DBG') echo "<code>$content</code>";
	}
	else if($output == 'SVG') {
		header("Content-type: image/svg+xml");
		echo '<?xml version="1.0" encoding="UTF-8" standalone="no"?>\n';
		echo $svg;	
	}
	else {
		echo $svg;
	}
?>