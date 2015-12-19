<?php 
	if(isset($_GET['page']) && $_GET['page']=='barony') {
		$backgroundStyle = 'background-color:black; background-image:url(http://www.matagot.com/IMG/jpg/barony_fond.jpg);background-repeat:no-repeat;background-position:top center;';
		$rubriqueHeight = 185;
		$rubriqueElt = "&nbsp;";
		$leftFrameElt = '<p>Cher joueur,</p><p>Vous avez achet&eacute; le jeu Barony ainsi que ses 4 sacs en tissu.</p><p>Nous vous remercions pour votre confiance !</p><p>Veuillez remplir ce formulaire pour recevoir vos sacs.</p><p>Ludiquement,</p><p>L\'&eacute;quipe Matagot.</p>';
	} else {
		$backgroundStyle = '';
		$rubriqueHeight = 115;
		$rubriqueElt = '<img src="'. ASSETS_PATH .'images/site_bandeau_services_fr.jpg" width="1008" height="100" />';
		$leftFrameElt = '<img src="'. ASSETS_PATH .'images/SAV_perso.jpg" width="267px" height="527px" />';
	}
?>
<div style="<?php echo $backgroundStyle; ?>">

<table width="1008" border="0" cellspacing="0" cellpadding="0" align="center">
	<tr>
		<td height="12"></td>
	</tr>
	<tr>
		<td>
			<table width="1008" border="0" cellspacing="0" cellpadding="0">
      		<tr>
		        <td width="6" height="66"><img src="<?php echo ASSETS_PATH; ?>images/LOGO_MATAGOT_coin.png" width="6" height="66" /></td>
		        <td width="337" height="66" bgcolor="#000000"><a href='/spip?page=sommaire&lang=fr'><img src="<?php echo ASSETS_PATH; ?>images/LOGO_MATAGOT.jpg" width="337" height="66" border="0" /></a></td>
		        <td class="menuhaut_espace"></td>
		        <td class="menuhaut"><a href="/spip?page=news2&lang=fr" class="lienmenuhaut">News</a></td>
		        <td class="menuhaut_espace"></td>
		        <td class="menuhaut"><a href=/spip?page=catalogue class="lienmenuhaut">Catalogue</a></td>
		        <td class="menuhaut_espace"></td>
		        <td class="menuhaut"><a href="http://forum.matagot.com/" target=_blank class="lienmenuhaut">Forum</a></td>
		        <td class="menuhaut_espace"></td>
		        <td class="menuhaut"><a href="http://matagot.com/connexion/" target=_blank class="lienmenuhaut">Matagot<br/>Connexion</a></td>
		        <td class="menuhaut_espace"></td>
		        <td class="menuhaut"><a href="/spip?page=espacepro&lang=fr" class="lienmenuhaut">Espace<br />Pro - presse</a></td>
		        <td class="menuhaut_espace"></td>
		        <td class="menuhaut"><a href="/spip?page=service&lang=fr" class="lienmenuhaut">Services<br/>et SAV</a></td>
		        <td class="menuhaut_espace"></td>
		        <td class="menuhaut"><a href="/spip?page=boutique&lang=fr" class="lienmenuhaut">Boutique</a></td>
      		</tr>
			</table>
		</td>
	</tr>

	<tr>
		<td class="lientextegris">
			<a href="?lang=fr_FR"><img src="<?php echo ASSETS_PATH; ?>images/flag_fr.gif" width="28" height="20" alt=""/></a>
			<a href="?lang=en_US"><img src="<?php echo ASSETS_PATH; ?>images/flag_en.gif" width="28" height="20" alt=""/></a>
			<span class="breadcrumb">Services et SAV</span>
		</td>
	</tr>      
	
	<script type="text/javascript" src="assets/matagot/js/md5.js"></script>
	<!--  Dynamic Extension -->
	<script type="text/javascript">
		function getOstSessionId(){
		    var jsId = document.cookie.match(/OSTSESSID=[^;]+/);
		    if(jsId != null) {
		        if (jsId instanceof Array)
		            jsId = jsId[0].substring(10);
		        else
		            jsId = jsId.substring(10);
		    }
		    return jsId;
		}
		
		function getFieldName(fieldId) {
			var code = md5(getOstSessionId()+"-field-id-"+fieldId);
			return code.substring(code.length - 16);
		}

		function getPropertyDisplayFieldName(fieldId, itemId) {
			var code = md5(getOstSessionId()+"-pdisp-"+fieldId+'-'+itemId);
			return code.substring(code.length - 16);
		}
	</script>
	
	<tr>
		<td height="<?php echo $rubriqueHeight; ?>" align="left" valign="top" class="nomrubriqueGD">
			<?php echo $rubriqueElt; ?>
		</td>
	</tr>

	<tr>
		<td>
			<div id="cadreblanc" style="float: left; width:267px;"><?php echo $leftFrameElt; ?></div>
			<div id="cadreblanc" style="float: right; width:675px;" >
			