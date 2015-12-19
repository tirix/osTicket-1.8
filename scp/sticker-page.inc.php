<?php

//TicketForm::ensureDynamicDataView();
// $tickets = array();
// foreach($tids as $tid) {
// 	$ticket = array();
// 	foreach (DynamicFormEntry::forTicket($tid, true) as $form) {
// 		foreach ($form->getAnswers() as $answer) {
// 			$tag = mb_strtolower($answer->getField()->get('name'))
// 			?: 'field.' . $answer->getField()->get('id');
// 			$ticket[$tag] = $answer;
// 		}
// 	array_push($tickets, $ticket);
// 	}
// }
//
//echo "<pre>".var_dump($tickets)."</pre>";

$select = 'SELECT ticket.ticket_id, ticket.number as number, user.name as name, email.address as email, ticket.user_id as user_id, field.name as field_name, answer.value as value, answer.value_id as value_id';

$from =' FROM '.TICKET_TABLE.' ticket '
      .' LEFT JOIN '.USER_TABLE.' user ON user.id = ticket.user_id '
      .' LEFT JOIN '.USER_EMAIL_TABLE.' email ON user.id = email.user_id '
      .' LEFT JOIN '.FORM_ENTRY_TABLE.' entry ON (entry.object_id = ticket.ticket_id AND entry.object_type = \'T\') OR (entry.object_id = ticket.user_id AND entry.object_type = \'U\') '
      .' LEFT JOIN '.FORM_ANSWER_TABLE.' answer ON answer.entry_id = entry.id '
      .' LEFT JOIN '.FORM_FIELD_TABLE.' field ON field.id = answer.field_id AND field.form_id = entry.form_id '
      ;

$where = 'WHERE ticket.ticket_id IN ('.implode(',',$tids).')';

$query ="$select $from $where ORDER BY ticket.created DESC";

// Fetch the results
$results = array();
$res = db_query($query);
$tid = 0;
$tickets = array();
unset($ticket);
while ($row = db_fetch_array($res))
{
	if($row['ticket_id'] != $tid) 
	{
		if(isset($ticket)) array_push($tickets, $ticket);
		$ticket = array();
    	$ticket['id'] = $row['ticket_id'];
    	$ticket['number'] = $row['number'];
    	$ticket['email'] = $row['email'];
		$tid = $ticket['id'];
	} 
    $ticket[$row['field_name']] = $row['value'];
}
if(isset($ticket)) array_push($tickets, $ticket);

//echo "<pre>".$query."</pre>";
// echo "<pre>";
// var_dump($tickets);
// echo "</pre>";

?>
