<?php
global $cfg;

if (!$info['title'])
    $info['title'] = 'Print Tickets Stickers';

?>
<h3><?php echo $info['title']; ?></h3>
<b><a class="close" href="#"><i class="icon-remove-circle"></i></a></b>
<div class="clear"></div>
<hr/>
<?php
if ($info['error']) {
    echo sprintf('<p id="msg_error">%s</p>', $info['error']);
} elseif ($info['warn']) {
    echo sprintf('<p id="msg_warning">%s</p>', $info['warn']);
} elseif ($info['msg']) {
    echo sprintf('<p id="msg_notice">%s</p>', $info['msg']);
} elseif ($info['notice']) {
   echo sprintf('<p id="msg_info"><i class="icon-info-sign"></i> %s</p>',
           $info['notice']);
}
?>
<form method="get" name="print" id="print" >
<input type="hidden" name="output" value="PDF" />
<input type="hidden" name="tids" id="_tids"/>
<div style="width:100%;" class="pull-left">
	<table width="100%">
	<tbody>
		<tr>
			<td class="multi-line required" style="min-width:120px;">
                Format de la page d'etiquettes:
             </td>
			<td>
				<div>
					<select name="pageFormat" id="_pageFormat" onchange="updatePreview()">
			        	<option value="L4743">L4743</option>
			        </select>
				</div>
			</td>
		</tr>
		<tr>
			<td class="multi-line required" style="min-width:120px;">
                Nombre d'etiquettes a sauter en haut de la page:
             </td>
			 <td>
				<div>
					<select name="skipStickers" id="_skipStickers" data-placeholder="SŽlectionner" onchange="updatePreview()">
			        	<option value="0">aucune</option>
			            <option value="2">1 ticket (2 etiquettes)</option>
			            <option value="4">2 tickets (4 etiquettes)</option>
			            <option value="6">3 tickets (6 etiquettes)</option>
			            <option value="8">4 tickets (8 etiquettes)</option>
			            <option value="10">5 tickets (10 etiquettes)</option>
			            </select>
				</div>
			</td>
		</tr>
		<tr>
			<td class="multi-line required" style="min-width:120px;">
                Preview:
             </td>
			<td>
				<div><iframe id="_sticker-preview" src="" width = "125px "height = "145px" /></div>
			</td>
		</tr>
	</tbody>
	</table>
</div>
<div id="ticket-print" style="display:block; margin:5px;">
        <hr>
        <p class="full-width">
            <span class="buttons pull-left">
                <input type="reset" value="<?php echo __('Reset'); ?>">
                <input type="button" name="cancel" class="close"
                value="<?php echo __('Cancel'); ?>">
            </span>
            <span class="buttons pull-right">
                <input type="button" name="printStickers" class="printStickers" value="<?php
                echo $verb ?: __('Print'); ?>">
            </span>
         </p>
</div>
</form>
<div class="clear"></div>
<script type="text/javascript">

function pagePreferences() {
	var skip = $('#_skipStickers').val();
	var format = $('#_pageFormat').val();
	var tids = '';
	var sep = '';
	$('form#tickets input[name="tids[]"]:checkbox:checked')
    .each(function(index, elem) {
        tids = tids + sep + $(elem).val();
        sep = '-';
    });
	$('input#_tids').val(tids);
	return 'sticker-page.php?skip='+skip+'&format='+format+'&tids='+tids;
}

$('.dialog').delegate('input.printStickers', 'click', function(e) {
    e.preventDefault();
    $(this).parents('div.dialog')
    .hide()
    .removeAttr('style');
    $.toggleOverlay(false);

	url = pagePreferences() + '&output=PDF';
	window.open(url,'_blank');
    return false;
});

function updatePreview(){
	url = pagePreferences();
	$('iframe#_sticker-preview').attr('src', url + '&output=SVGX&scale=.1');
}

updatePreview();

</script>

