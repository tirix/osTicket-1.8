<?php
	require_once('staff.inc.php');
	header('Content-Type: application/csv; charset=UTF-8');
	header('Content-disposition: attachment; filename="Matagot Tickets Export '.date('ymd').'.csv"');
	
	function unicode_unescape($str) {
		$str = preg_replace('/\\\\"/', '"', $str);
		return $str = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
		    return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
		}, $str);
	}
	
	function getListValue($formData) {
		global $lists;

		$pattern = '/\\{\\"(.*)\\"\\:\\"(.*)\\"\\}/';
		if(preg_match($pattern, $formData, $matches)) {
			if(is_numeric($matches[1])) {
				return array('value' => $matches[2], 'extra' => $lists[$matches[1]]['extra']);
			}
			else
				return array('value' => $matches[2], 'extra' => array());
		}
		else
			return array('value' => '', 'extra' => array());
	}
	
	function getPropertyValues($formData) {
		global $fields;

		$pattern = '/\\"((?:[^"\\\\]|\\\\.)*)\\"\\:\\"((?:[^"\\\\]|\\\\.)*)\\"/';
		if(preg_match_all($pattern, $formData, $matches)) {
			$values = array();
			for($i = 0;$i<count($matches[2]);$i++) {
				array_push($values, array(
					'type' => $fields[$matches[1][$i]]['type'],
					'field_name' => $fields[$matches[1][$i]]['label'],
					'value' => unicode_unescape($matches[2][$i])));
			}
			return $values;
		}
		else
			return array();
	}

	function push_value($field_name, $value, $field_type) {
		global $columns, $ticket;
		
		if(!in_array($field_name, $columns)) array_push($columns, $field_name);
		if(substr($field_type, 0, 5) == 'list-' || $field_type == 'choices') {
			$formValue = getListValue($value);
			$value = unicode_unescape($formValue['value']);
			if(count($formValue['extra']) > 0) {
				foreach($formValue['extra'] as $extra_field_name => $extra_value)
					push_value($field_name.'_'.$extra_field_name, $extra_value, 'text');
			}
		}
		$value = str_replace('"', "'",$value);
		if($value == '') $value = 'N/V';
		$ticket[$field_name] = $value;
	}
	
	// Get fields data
	$fields_query = 'SELECT id, type, label FROM ost_form_field';
	$res = db_query($fields_query);
	$fields = array();
	while ($row = db_fetch_array($res))
		$fields[$row['id']] = $row;
	
	// Get list items data
	$list_items_query = 'SELECT * FROM ost_list_items';
	$dont_list = array('Piece Types Description', 'Piece Image URL');
	$res = db_query($list_items_query);
	$lists = array();
	while ($row = db_fetch_array($res)) {
		if($row['extra'] == '') $row['extra'] = array();
		else $row['extra'] = array('code' => $row['extra']);
		$properties = getPropertyValues($row['properties']);
		foreach($properties as $property) {
			if(!in_array($property['field_name'], $dont_list))
				$row['extra'][$property['field_name']] = $property['value'];
		}
		$lists[$row['id']] = $row;
	}

	// Main Query
	$select = 'SELECT ticket.ticket_id, ticket.number as number, user.name as name, email.address as email, ticket.user_id as user_id, ticket.source as source, ticket.created as created, ticket.closed as closed, topic.topic as topic, field.name as field_name, field.type as field_type, answer.value as value, answer.value_id as value_id';
	
	$from =' FROM '.TICKET_TABLE.' ticket '
		.' LEFT JOIN '.TOPIC_TABLE.' topic ON topic.topic_id = ticket.topic_id '
		.' LEFT JOIN '.USER_TABLE.' user ON user.id = ticket.user_id '
		.' LEFT JOIN '.USER_EMAIL_TABLE.' email ON user.id = email.user_id '
		.' LEFT JOIN '.FORM_ENTRY_TABLE.' entry ON (entry.object_id = ticket.ticket_id AND entry.object_type = \'T\') OR (entry.object_id = ticket.user_id AND entry.object_type = \'U\') '
		.' LEFT JOIN '.FORM_ANSWER_TABLE.' answer ON answer.entry_id = entry.id '
		.' LEFT JOIN '.FORM_FIELD_TABLE.' field ON field.id = answer.field_id AND field.form_id = entry.form_id '
		;
	
	$where = 'WHERE 1';
	
	$query ="$select $from $where ORDER BY ticket.created DESC";
	
	// Fetch the results
	$results = array();
	$ticket_columns = array('ticket_id', 'number', 'email', 'created', 'closed', 'topic', 'source');
	$columns = $ticket_columns;
	$res = db_query($query);
	$tickets = array();
	$tid = 0;
	unset($ticket);
	while ($row = db_fetch_array($res))
	{
		if($row['ticket_id'] != $tid)
		{
			if(isset($ticket)) array_push($tickets, $ticket);
			$ticket = array();
			foreach($ticket_columns as $field) $ticket[$field] = $row[$field];
			$tid = $row['ticket_id'];
		}
		push_value($row['field_name'], $row['value'], $row['field_type']);
	}
	if(isset($ticket)) array_push($tickets, $ticket);

	// List the headers
	foreach($columns as $field) echo "\"$field\";";
	echo "\n";
	
	// List the datas
	foreach($tickets as $ticket)
	{
		foreach($columns as $field)
			if(!isset($ticket[$field])) echo "\"N/A\";";
			else echo "\"".$ticket[$field]."\";";
		echo "\n";
	}
?>
