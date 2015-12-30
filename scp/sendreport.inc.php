<?php

function report() {
	// Get start and end dates
	//$query = 'SELECT DATE_SUB(DATE(NOW()), INTERVAL 10 DAY) AS `from`, DATE_SUB(DATE(NOW()), INTERVAL 3 DAY) AS `until`';
	$query = 'SELECT DATE_SUB( CURDATE( ) - 3, INTERVAL( WEEKDAY( CURDATE( ) - 3 ) ) + 7 DAY ) AS `from`, DATE_SUB( CURDATE( ) - 3, INTERVAL( WEEKDAY( CURDATE( ) - 3) ) + 1 DAY ) AS `until`';
	$res = db_query($query);
	$dates = db_fetch_array($res);
	
	$columns = array(
			array('name' => 'Topic', 'width' => '50%', 'align' => 'left'),
			array('name' => 'Source', 'width' => '10%', 'align' => 'center'),
			array('name' => 'Total', 'width' => '10%', 'align' => 'right'),
			array('name' => 'Overdue', 'width' => '10%', 'align' => 'right', 'link' => 'http://www.matagot.com/support/scp/tickets.php?status=overdue'),
			array('name' => 'In Time', 'width' => '10%', 'align' => 'right'),
			array('name' => 'Duration', 'width' => '10%', 'align' => 'right'),
	);

	displayTable("Weekly Statistics", "Tickets opened from ".$dates['from']." until ".$dates['until'].".", $columns, queryTickets('t.created > DATE("'.$dates['from'].'") AND t.created <= DATE("'.$dates['until'].'")'));
	displayTable("Opened Tickets", "All currently opened tickets in the system.", $columns, queryTickets("t.closed IS NULL"));
	displayTable("Overall Statistics", "All tickets in the system (including closed tickets).", $columns, queryTickets("1"));
}

function queryTickets($where) {
	return 'SELECT 
			IFNULL(ht.topic,"(No topic)") AS Topic, 
			t.source AS Source, COUNT(*) AS Total, 
			SUM(t.isoverdue) AS Overdue, 
			COUNT(*)-SUM(t.isoverdue) AS "In Time",
			ROUND(SUM(DATEDIFF(t.closed, t.created))/COUNT(t.closed)) AS "Duration"
		FROM ost_ticket t
		LEFT JOIN ost_help_topic ht ON ht.topic_id = t.topic_id
		WHERE '.$where.'
		GROUP BY t.topic_id, t.source
		ORDER BY Total DESC';
}

function displayTable($header, $title, $columns, $query) {
	$th_style="background-color:#eee;
    color:#000;
    vertical-align:top;
    padding: 4px 5px;";
	
	$td_style="background:#fff;
    border:1px solid #fff;
    padding:1px;
    vertical-align:top;";

	$h2_style = "margin:0 0 0.7em; padding:0; font-size:20px; color:#0A568E;";
	echo "<h2 style='$h2_style'>$header</h2>";
	echo $title;
?>
	<br/>
	<table class="list" cellspacing="1" cellpadding="0" width="840"
	style = "clear:both;
	    background:#ccc;
	    margin: 2px 0 25px 0;
	    border-bottom: 1px solid #ccc;
	    font-size: 14px;">
	  <thead>
		<tr>
	<?php
		foreach ($columns as $column) {
			echo '<th align="'.$column['align'].'" width="'.$column['width'].'" style="'.$th_style.'">';
			if(isset($column['link'])) echo '<a style="color:#000;" href="'.$column['link'].'">'.$column['name'].'</a>';
			else echo $column['name'];
			echo '</th>';
		}
	?>
		</tr>
	  </thead>
	  <tbody>
	<?php
		$total = 0;
		$res = db_query($query);
		if(!$res) {
    		echo "<pre>$query</pre>";
    		echo 'Query Failed!<br/>';
		}
		while ($row = db_fetch_array($res)) {
		echo '<tr>';
		foreach ($columns as $column) {
			echo '<td align="'.$column['align'].'" style="'.$td_style.'">'.$row[$column['name']].'</td>';
			}
		echo '</tr>';
		$total += $row['Total'];
		} //end of while
	?>
	</tbody>
	<tfoot>
		<tr>
			<td colspan="<?php echo count($columns); ?>" style="padding: 2px;">Total: <?php echo $total; ?> tickets.</td>
		</tr>
	</tfoot>
	</table>
	<?php
}


?>
<html>
<body style="font-family: 'Lato', 'Helvetica Neue', arial, helvetica, sans-serif;
  font-weight: 400;
  letter-spacing: 0.15px;
  -webkit-font-smoothing:antialiased;
          font-smoothing:antialiased;
          background:#eee;
    color:#000;
    font-size:14px;
    margin:0;
    padding:0;">
<div id="container" style="
    width: 860px;
    margin: 20px auto 20px auto;">

<div id="content" style="clear: both;
    border: 1px solid #aaa;
    border-bottom: 3px solid #bbb;
    padding: 10px 10px 20px 10px;
    background: #fff;">
<div style="float:left; "><img src="http://www.matagot.com/newsletter/Newsletter_00/images/logo_simple.png" style="width: 70px; margin-right: 20px; margin-bottom: 10px;"/></div>
<div><h1 style="color:#0A568E;">Matagot SAV - Weekly Dashboard</h1></div>
<div style="clear:both"></div>
<?php report(); ?>
</div>
</div>
</body>
</html>

