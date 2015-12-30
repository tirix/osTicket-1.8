<?php
//define('INCLUDE_DIR', '../include/');
//define('PEAR_DIR',INCLUDE_DIR.'pear/');
require('../bootstrap.php');
Bootstrap::loadConfig();
Bootstrap::defineTables(TABLE_PREFIX);
Bootstrap::i18n_prep();
Bootstrap::loadCode();
Bootstrap::connect();

$ost = $ost=osTicket::start();
//Internationalization::bootstrap();

// We'll have to send emails
//include(INCLUDE_DIR.'class.orm.php');
//class Form {}
//abstract class AbstractForm {}
//interface TemplateVariable {}
//include(INCLUDE_DIR.'class.forms.php');
//include(INCLUDE_DIR.'class.email.php');

///// Connect to the Ticket Database!
// class Misc { static function __rand_seed($value=0) { } }
// include(INCLUDE_DIR.'ost-config.php');
// include(INCLUDE_DIR.'mysqli.php');
// $options = array();

// $content = '';
// if (!$ost_db = db_connect(DBHOST, DBUSER, DBPASS, $options)) {
// 	$content .= sprintf('Unable to connect to the database — %s<br/>',db_connect_error());
// }
// $__db->unbuffered_result = false;
// if(!$ost_db = db_select_database(DBNAME)) {
// 	$content .= sprintf('Unknown or invalid database: %s<br/>',DBNAME);
// }
// $content .= 'Successfully connected to the ticket Database.<br/>';
///// Connect to the Ticket Database

// Get report message contents
ob_start();
include('./sendreport.inc.php');
$msg = ob_get_contents();
ob_end_clean();
//echo $msg;
//exit;

// Retrieve configuration
$config = array();
$query = "SELECT `key`, `value` FROM `ost_config` WHERE `key` IN ('alert_email_id', 'weekly_reports_dept_id', 'weekly_reports_active')";
$res = db_query($query);
while($row = db_fetch_array($res)) $config[$row['key']] = $row['value'];

if(!$config['weekly_reports_active']) {
	echo "Matagot weekly reports are not active.";
	exit;
}


// Retrieve the Email for sending alerts
$email=Email::lookup($config['alert_email_id']);


// Retrieve list of staff who shall receive the report
$dept_id = $config['weekly_reports_dept_id'];

$query = "SELECT s.staff_id, s.email, s.username, s.firstname, s.lastname FROM ost_staff s LEFT JOIN ost_staff_dept_access sd ON (s.staff_id = sd.staff_id AND sd.dept_id = $dept_id)
WHERE (s.dept_id = $dept_id AND sd.dept_id IS NULL) OR (sd.dept_id = $dept_id AND flags & 1 = 1)";
$res = db_query($query);

while($dest = db_fetch_array($res))
{
	if($email->send($dest['email'],'Weekly Matagot SAV Report',
			Format::sanitize($msg),
			null, array('reply-tag'=>false)))
		echo "Successfully sent report to ".$dest['firstname']."<br/>";
	else 
		echo "Error when sending report to ".$dest['firstname']."<br/>";
}

/*
 if(!$_POST['email_id'] || !($email=Email::lookup($_POST['email_id'])))
 	$errors['email_id']=__('Select from email address');
 	http://www.matagot.com/support/assets/matagot/images/LOGO_MATAGOT.jpg
 	*/

 
 
 ?>