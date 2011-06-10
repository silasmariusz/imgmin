<html>
<head>
<style type="text/css">
body { font-family: arial; font-size:small }
.r { display:table-row }
.c { display:table-cell }
.err { font-size:200%; color: red }
.dir { font-family: Courier New, sans-serif }
code { color: green }
h3 { margin: 0px; margin-bottom: 3px }
#thumbs { width:280px }
</style>
</head>
<body>
<div id="spinner" style="background-color:#fff; position:absolute"><img src="static/img/spinner.gif"></div>
<script language="javascript" src="static/js/jquery-1.6.1.js"></script>
<script>
jQuery.fn.center = function () {
	this.css("position","absolute");
	this.css("top", ( $(window).height() - this.height() ) / 2+$(window).scrollTop() + "px");
	this.css("left", ( $(window).height() - this.width() ) / 2+$(window).scrollLeft() + "px");
	return this;
}
$('#spinner').center();
$('#spinner').show();
</script>

<?php

/*
 * Image minimizer gui.
 * 
 * Author: Ryan Flynn <parseerror+imgmin@gmail.com>
 * 
 * Resamples/re-encodes images in popular web formats to reduce size to reduce bandwidth.
 * Relies on
 * 	ImageMagick http://www.imagemagick.org/
 *	pngnq http://pngnq.sourceforge.net/
 */

define('THUMBSIZE', 100);
define('TEMPDIR', './tmp/');
define('TEMPABS', realpath('.') . '/' . TEMPDIR);
define('IMGMINPATH', './');
#define('IMAGEMAGICKPATH', '/home/rflynn/ImageMagick-6.7.0-6/utilities/');
define('IMAGEMAGICKPATH', '/usr/local/bin/');

// check temp dir setup
if (!file_exists(TEMPDIR))
	tempdir_error('does not exist', true);
else if (!is_writable(TEMPDIR))
	tempdir_error('is not writable by the application');

/*
 * for each image in [original + original.resampled]:
 * 	calculate difference metrics between it and the original image
 *	generate jquery/javascript which adds it to the thumb panel
 */
function displayThumb($orig)
{
	$i = 0;
	$show = array_merge(array($orig), $orig->resampled);
	foreach ($show as $res)
	{
		$pct = round($res->bytes / $orig->bytes * 100);
		$sizediff = $orig->bytes - $res->bytes;
		$cmp = $res->cmp($orig) . '%';
		preg_match('/(\d{1,2}\.\d\d).(jpe?g|gif|png)$/i', $res->path, $m);
		$target_diff = @$m[1] ? $m[1] : '0';
?>
$('#thumbs').html($('#thumbs').html() +
'<div style="display:table; width:280px; margin-bottom:1px">' +
'	<div id="row<?= $i ?>" class="row" style="display:table-row">' +
'		<div class="c linkdiv" style="width:<?= THUMBSIZE ?>px"><a href="javascript:showThumb(\'row<?= $i ?>\', \'<?= $res->path ?>\')"><img src="<?= $res->thumbpath ?>" height="<?= $orig->thumbheight ?>" width="<?= $orig->thumbwidth ?>" style="border-width:1px"></a></div>' +
'		<div style="display:table-cell; text-align:left; vertical-align:top; padding:2px; border-top:1px solid #ccc">' +
'			<b><?= $target_diff ?><span style="color:#999;font-size:110%">&sigma;</span></b><br>' +
'			<div style="display:inline-block; margin-right:3px; width:<?= $pct ?>px; height:10px; background-image:url(static/img/progress-bar.png)"></div><?= $pct ?>%<br>' +
'			Size: <?= round($res->bytes / 1024.0, 1) ?> Kb<br>' +
'			Saved: <?= round($sizediff / 1024.0, 1) ?> Kb' +
'		</div>' +
'	</div>' +
'</div>')
<?php
		$i++;
	}
	printf("showThumb('row0', '%s')", $show[0]->path);
}

class Img
{
	public $tmpdir = TEMPDIR;

	function __construct($path, $thumbpx=THUMBSIZE)
	{
		$this->path = $path;
		$this->thumbpx = $thumbpx;
		$this->bytes = filesize($path);
		list($this->width, $this->height, $type, $attr) = getimagesize($path);
		$this->ext = strtolower(substr($path, strrpos($path, '.')+1));
		$thumbfactor = max($this->width / $thumbpx, $this->height / $thumbpx);
		$this->thumbwidth = $this->width / $thumbfactor;
		$this->thumbheight = $this->height / $thumbfactor;
		$this->thumbpath = $this->thumbnail();
		$this->resampled = array();
	}

	function thumbnail()
	{
		$thumbpath = $this->tmpdir . basename($this->path) . '.thumb.' . $this->ext;
		if (!file_exists($thumbpath))
		{
			$cmd = sprintf('%sconvert -resize %ux%u %s %s 2>&1',
				IMAGEMAGICKPATH, $this->thumbpx, $this->thumbpx, escapeshellarg($this->path), escapeshellarg($thumbpath));
			#echo "<pre>$cmd</pre>";
			exec($cmd, &$out, &$err);
			#echo '<pre>'.print_r($out,1).'</pre>';
			#print_r($err);
		}
		return $thumbpath;
	}

	function cmp($other)
	{
		$cmp = NAN;
		/*
		 * -metric: algorithm for comparison. SSIM > RMSE, but compare doesn't support it.
		 */
		$cmd = sprintf('%scompare -metric RMSE %s %s /dev/null 2>&1',
			IMAGEMAGICKPATH, escapeshellarg($this->path), escapeshellarg($other->path));
		#echo "<pre>$cmd</pre>";
		$sys = shell_exec($cmd);
		#echo "<pre>$sys</pre>";
		// extract pct difference
		if (preg_match('/\((.*?)\)/', $sys, $m))
			$cmp = round(floatval($m[1]) * 100, 2);
		return $cmp;
	}

	function resample()
	{
		switch ($this->ext)
		{
		case 'jpeg':
		case 'jpg': $this->resampleJPG(); break;
		case 'gif': $this->resampleGIF(); break;
		case 'png': $this->resamplePNG(); break;
		default:
			break;
		}
	}

	function resampleJPG()
	{
		foreach (array(0.5, 0.75, 1, 1.5, 2, 3, 4, 10) as $pct)
		{
			$newpath = sprintf('%s%s.%4.2f.%s', $this->tmpdir, basename($this->path), $pct, $this->ext);
			if (!file_exists($newpath))
			{
				$cmd = sprintf('%simgmin.sh %s %s %.2f', IMGMINPATH, escapeshellarg($this->path), escapeshellarg($newpath), $pct);
				#echo "<pre>$cmd</pre>";
				exec($cmd, &$out);
				#echo '<pre>'.print_r($out,1).'</pre>';
			}
			$this->resampled[] = new Img($newpath);
		}
	}

	function resampleGIF()
	{
		foreach (array(128, 64, 32, 16, 8) as $colors)
		{
			$newpath = $this->tmpdir . basename($this->path) . '.' . $colors . '.' . $this->ext;
			/*
			 *
			 */
			exec(sprintf('convert -colors %u -strip %s %s', $colors, escapeshellarg($this->path), escapeshellarg($newpath)));
			$this->resampled[] = new Img($newpath);
		}
	}

	function resamplePNG()
	{
		exec(sprintf('pngnq %s', escapeshellarg($this->path)));
		// path dictated by pngnq
		$newpath = substr($this->path, 0, strrpos($this->path, '.')) . '-nq8.png';
		$this->resampled[] = new Img($newpath);
	}

}

function safedir($dir)
{
	$safer = preg_replace('#^/+#', '', # disallow leading /
		preg_replace('#\.{2,}#', '', $dir)); # disallow ..
	return $safer;
}

function list_images($dir, $nth=null)
{
	$files = array();
	$d = opendir($dir);
	while (($f = readdir($d)))
		if (preg_match('/\.(jpe?g|gif|png)$/i', $f))
			$files[] = "$dir/$f";
	closedir($d);
	if ($nth !== null)
		return $files[$nth];
	return $files;
}

$DIR = @$_GET['dir'] ? safedir($_GET['dir']) : 'images';
$IMG = @$_GET['img'] ? $_GET['img'] : list_images($DIR, 0);
$old = new Img($IMG);
?>

<h1>imgmin demo: configurable lossy compression</h1>

<div style="display:table">
<div class="r">

<?php
$classify = array(
	array('Small',      200, array()),
	array('Medium',	    400, array()),
	array('Large',	    1e6, array()),
);
# partition images
$li = list_images($DIR);
asort($li);
foreach ($li as $img)
{
	list($width, $height, $type, $attr) = getimagesize($img);
	for ($i = 0; $i < count($classify); $i++)
	{
		if (max($width, $height) <= $classify[$i][1])
		{
			$classify[$i][2][] = $img;
			break;
		}
	}
}

foreach ($classify as $c)
{
	list($title, $size, $images) = $c;
	if ($images)
	{
?>
	<div class="c" style="vertical-align:top; width:<?= THUMBSIZE ?>px; padding:1px; border:1px solid #ccc; background-color:#eee">
		<h3><img src="static/img/folder.gif" style="vertical-align:text-top"> <?= $title ?></h3>
<?php
	foreach ($images as $f)
	{
		$img = new Img($f);
		$link = $PHP_SELF . '?img=' . urlencode($img->path);
		if ($DIR != 'images')
			$link .= 'dir='.urlencode($DIR);
?>
		<div style="display:inline-block; padding:0px; margin:0px; margin-right:1px; margin-bottom:1px"><a href="<?= $link ?>"><img src="<?= $img->thumbpath ?>" width="<?= $img->thumbwidth ?>" height="<?= $img->thumbheight ?>" style="border-width:1px; padding:0px; margin:0px"></a></div>
<?php
	}
?>
	</div>
<?php
	}
}
ob_flush();
?>

<div class="c">

<div style="display:table">
	<div class="r">
	</div>
	<div class="r">
		<div class="c" style="vertical-align:top; padding-left:10px">
			<div class="c"><h3>Resample Gallery</h3></div>
			<div id="thumbs"></div>
		</div>
		<div id="resampled" class="c" style="min-width:50%">
			<div style="position:absolute; padding:3px; z-index:2"><h3>Resampled</h3></div>
			<div style="background-image:url(static/img/bg.png)" >
				<img id="newoldbg" src="<?= htmlentities($old->path) ?>" style="position:absolute; z-index:0" width="<?= $old->width ?>" height="<?= $old->height ?>">
				<img id="newimg" src="" width="<?= $old->width ?>" height="<?= $old->height ?>" style="z-index:1">
			</div>
			<div>
				<div style="position:absolute; padding:3px"><h3>Original</h3></div>
				<div style="background-image:url(static/img/bg.png)" >
					<img id="oldimg" src="<?= htmlentities($old->path) ?>" width="<?= $old->width ?>" height="<?= $old->height ?>">
				</div>
			</div>
		</div>
	</div>
</div>

</div> <!-- row -->
</div> <!-- table -->

<script language="javascript">

function showThumb(a, path)
{

	$('#newoldbg').show()
	$('#newimg').hide()
	$('#newimg').attr('src', path)
	$('#newimg').fadeIn('fast', function()
	{
		$('#newoldbg').hide()
	})
	$('.row').css('background-color', '#fff')
	$('#' + a).css('background-color', '#ff9')
}


$(document).ready(function() {

<?php
ob_flush();
$old->resample();
displayThumb($old);
?>

})

$('#spinner').hide();

</script>
</body>
</html>

<?php

function www_user_and_group()
{
	$www_user = `whoami`; # FIXME: naughty naughty
	$www_groups = explode(' ', trim(`groups`)); # FIXME: naughty naughty
	$www_group = $www_groups[0];
	return array($www_user, $www_group);
}

function show_cmds($mkdir=false)
{
	list($www_user, $www_group) = www_user_and_group();
?>
	<div>
<?php
	if ($mkdir) {
?>
		<div><code>sudo mkdir <?= TEMPABS ?> # create directory</code></div>
<?php
	}
?>
		<div><code>sudo chgrp <?= $www_group ?> <?= TEMPABS ?> # change group</code></div>
		<div><code>sudo chmod g+w <?= TEMPABS ?> # grant writes to group</code></div>
	</div>
<?php
}

function tempdir_error($msg, $mkdir=false)
{
?>
	<div class="err">
		<div>Tempdir <span class="dir"><?= TEMPDIR ?></span> <?= $msg ?>.</div>
		<?php show_cmds($mkdir); ?>
	</div>
<?php
}

?>

