<?php
/*********************************************************************
    class.sla.php

    SLA
    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class SLA extends VerySimpleModel
implements TemplateVariable {

    static $meta = array(
        'table' => SLA_TABLE,
        'pk' => array('id'),
    );

    const FLAG_ACTIVE       = 0x0001;
    const FLAG_ESCALATE     = 0x0002;
    const FLAG_NOALERTS     = 0x0004;
    const FLAG_TRANSIENT    = 0x0008;
    const FLAG_REVOLVING    = 0x0010;
    const FLAG_IGN_ANSWERED = 0x0020;
    const FLAG_OPEN_HOURS   = 0x0040;
    
    var $_config;

    function getId() {
        return $this->id;
    }

    function getName() {
        return $this->getLocal('name');
    }

    function getGracePeriod() {
        return $this->grace_period;
    }

    function getInfo() {
        $base = $this->ht;
        $base['isactive'] = $this->flags & self::FLAG_ACTIVE;
        $base['disable_overdue_alerts'] = $this->flags & self::FLAG_NOALERTS;
        $base['enable_priority_escalation'] = $this->flags & self::FLAG_ESCALATE;
        $base['transient'] = $this->flags & self::FLAG_TRANSIENT;
        $base['revolving'] = $this->flags & self::FLAG_REVOLVING;
        $base['ignore_answered'] = $this->flags & self::FLAG_IGN_ANSWERED;
        $base['open_hours_only'] = $this->flags & self::FLAG_OPEN_HOURS;
        return $base;
    }

    function getCreateDate() {
        return $this->created;
    }

    function getUpdateDate() {
        return $this->updated;
    }

    function isActive() {
        return $this->flags & self::FLAG_ACTIVE;
    }

    function ignores_answered() {
    	return $this->flags & self::FLAG_IGN_ANSWERED;
    }
    
    function openHoursOnly() {
    	return $this->flags & self::FLAG_OPEN_HOURS;
    }
    
    function isRevolving() {
    	return $this->flags & self::FLAG_REVOLVING;
    }
    
    function isTransient() {
        return $this->flags & self::FLAG_TRANSIENT;
    }

    function sendAlerts() {
        return 0 === ($this->flags & self::FLAG_NOALERTS);
    }

    function alertOnOverdue() {
        return $this->sendAlerts();
    }

    function priorityEscalation() {
        return $this->flags && self::FLAG_ESCALATE;
    }

	/**
	 * Returns the Due date for a given ticket
	 */
    function getDueDate($ticket) {
    	$dt = new DateTime($ticket->getCreateDate());
    	if($this->isRevolving()) $dt = new DateTime($ticket->getLastResponseDate());

    	$dept = $ticket->getDept();
    	 
    	// if SLA counts "calendar" hours, simply add the grace period
    	if(!$this->openHoursOnly() || !$dept 
    			|| !$dept->getWorkingTime() || $dept->getWorkingTime()->is24_7()) {
	    	return $dt
	    	->add(new DateInterval('PT' . $this->getGracePeriod() . 'H'))
	    	->format('Y-m-d H:i:s');
    	} else {
    		return $dept->getWorkingTime()->addWithinWorkingHours($dt, $this->getGracePeriod())
	    	->format('Y-m-d H:i:s');
    	}
    }
    
    function getTranslateTag($subtag) {
        return _H(sprintf('sla.%s.%s', $subtag, $this->getId()));
    }

    function getLocal($subtag) {
        $tag = $this->getTranslateTag($subtag);
        $T = CustomDataTranslation::translate($tag);
        return $T != $tag ? $T : $this->ht[$subtag];
    }

    static function getLocalById($id, $subtag, $default) {
        $tag = _H(sprintf('sla.%s.%s', $subtag, $id));
        $T = CustomDataTranslation::translate($tag);
        return $T != $tag ? $T : $default;
    }

    // TemplateVariable interface
    function asVar() {
        return $this->getName();
    }

    static function getVarScope() {
        return array(
            'name' => __('SLA Plan'),
            'graceperiod' => __("Grace Period (hrs)"),
        );
    }

    function update($vars, &$errors) {

        if (!$vars['grace_period'])
            $errors['grace_period'] = __('Grace period required');
        elseif (!is_numeric($vars['grace_period']))
            $errors['grace_period'] = __('Numeric value required (in hours)');

        if (!$vars['name'])
            $errors['name'] = __('Name is required');
        elseif (($sid=SLA::getIdByName($vars['name'])) && $sid!=$vars['id'])
            $errors['name'] = __('Name already exists');

        if ($errors)
            return false;

        $this->name = $vars['name'];
        $this->grace_period = $vars['grace_period'];
        $this->notes = Format::sanitize($vars['notes']);
        $this->flags =
              ($vars['isactive'] ? self::FLAG_ACTIVE : 0)
            | (isset($vars['disable_overdue_alerts']) ? self::FLAG_NOALERTS : 0)
            | (isset($vars['enable_priority_escalation']) ? self::FLAG_ESCALATE : 0)
            | (isset($vars['transient']) ? self::FLAG_TRANSIENT : 0)
            | (isset($vars['revolving']) ? self::FLAG_REVOLVING : 0)
            | (isset($vars['ignore_answered']) ? self::FLAG_IGN_ANSWERED : 0)
            | (isset($vars['open_hours_only']) ? self::FLAG_OPEN_HOURS : 0)
            ;
            
        if ($this->save())
            return true;

        if (isset($this->id)) {
            $errors['err']=sprintf(__('Unable to update %s.'), __('this SLA plan'))
               .' '.__('Internal error occurred');
        } else {
            $errors['err']=sprintf(__('Unable to add %s.'), __('this SLA plan'))
               .' '.__('Internal error occurred');
        }

        return false;
    }

    function save($refetch=false) {
        if ($this->dirty)
            $this->updated = SqlFunction::NOW();

        return parent::save($refetch || $this->dirty);
    }

    function delete() {
        global $cfg;

        if(!$cfg || $cfg->getDefaultSLAId()==$this->getId())
            return false;

        //TODO: Use ORM to delete & update
        $id=$this->getId();
        $sql='DELETE FROM '.SLA_TABLE.' WHERE id='.db_input($id).' LIMIT 1';
        if(db_query($sql) && ($num=db_affected_rows())) {
            db_query('UPDATE '.DEPT_TABLE.' SET sla_id=0 WHERE sla_id='.db_input($id));
            db_query('UPDATE '.TOPIC_TABLE.' SET sla_id=0 WHERE sla_id='.db_input($id));
            db_query('UPDATE '.TICKET_TABLE.' SET sla_id='.db_input($cfg->getDefaultSLAId()).' WHERE sla_id='.db_input($id));
        }

        return $num;
    }

    /** static functions **/
    static function getSLAs($criteria=array()) {

       $slas = self::objects()
           ->order_by('name')
           ->values_flat('id', 'name', 'flags', 'grace_period');

        $entries = array();
        foreach ($slas as $row) {
            $row[2] = $row[2] & self::FLAG_ACTIVE;
            $entries[$row[0]] = sprintf(__('%s (%d hours - %s)'
                        /* Tokens are <name> (<#> hours - <Active|Disabled>) */),
                        self::getLocalById($row[0], 'name', $row[1]),
                        $row[3],
                        $row[2] ? __('Active') : __('Disabled'));
        }

        return $entries;
    }

    static function getSLAName($id) {
        $slas = static::getSLAs();
        return @$slas[$id];
    }

    static function getIdByName($name) {
        $row = static::objects()
            ->filter(array('name'=>$name))
            ->values_flat('id')
            ->first();

        return $row ? $row[0] : 0;
    }

    static function create($vars=false, &$errors=array()) {
        $sla = parent::create($vars);
        $sla->created = SqlFunction::NOW();
        return $sla;
    }

    static function __create($vars, &$errors=array()) {
        $sla = self::create($vars);
        $sla->save();
        return $sla;
    }
}
?>
