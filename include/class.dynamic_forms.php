<?php
/*********************************************************************
    class.dynamic_forms.php

    Forms models built on the VerySimpleModel paradigm. Allows for arbitrary
    data to be associated with tickets. Eventually this model can be
    extended to associate arbitrary data with registered clients and thread
    entries.

    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require_once(INCLUDE_DIR . 'class.orm.php');
require_once(INCLUDE_DIR . 'class.forms.php');
require_once(INCLUDE_DIR . 'class.forms-matagot.php');
require_once(INCLUDE_DIR . 'class.list.php');
require_once(INCLUDE_DIR . 'class.filter.php');
require_once(INCLUDE_DIR . 'class.signal.php');

/**
 * Form template, used for designing the custom form and for entering custom
 * data for a ticket
 */
class DynamicForm extends VerySimpleModel {

    static $meta = array(
        'table' => FORM_SEC_TABLE,
        'ordering' => array('title'),
        'pk' => array('id'),
        'joins' => array(
            'fields' => array(
                'reverse' => 'DynamicFormField.form',
            ),
        ),
    );

    // Registered form types
    static $types = array(
        'T' => 'Ticket Information',
        'U' => 'User Information',
        'O' => 'Organization Information',
    );

    var $_form;
    var $_fields;
    var $_has_data = false;
    var $_dfields;

    function getFields($cache=true) {
        if (!$cache)
            $fields = false;
        else
            $fields = &$this->_fields;

        if (!$fields) {
            $fields = new ListObject();
            foreach ($this->getDynamicFields() as $f)
                $fields->append($f->getImpl($f));
        }

        return $fields;
    }

    function getDynamicFields() {
        if (!isset($this->id))
            return array();
        elseif (!$this->_dfields) {
            $this->_dfields = DynamicFormField::objects()
                ->filter(array('form_id'=>$this->id))
                ->all();
            foreach ($this->_dfields as $f)
                $f->setForm($this);
        }
        return $this->_dfields;
    }

    // Multiple inheritance -- delegate to Form
    function __call($what, $args) {
        $delegate = array($this->getForm(), $what);
        if (!is_callable($delegate))
            throw new Exception(sprintf(__('%s: Call to non-existing function'), $what));
        return call_user_func_array($delegate, $args);
    }

    function getField($name, $cache=true) {
        foreach ($this->getFields($cache) as $f) {
            if (!strcasecmp($f->get('name'), $name))
                return $f;
        }
        if ($cache)
            return $this->getField($name, false);
    }

    function hasField($name) {
        return ($this->getField($name));
    }


    function getTitle() { return $this->getLocal('title'); }
    function getInstructions() { return $this->getLocal('instructions'); }

    function getForm($source=false) {
        if (!$this->_form || $source) {
            $fields = $this->getFields($this->_has_data);
            $this->_form = new Form($fields, $source, array(
                'title'=>$this->getLocal('title'), 'instructions'=>$this->getLocal('instructions')));
        }
        return $this->_form;
    }

    function isDeletable() {
        return $this->get('deletable');
    }

    function disableFields(array $ids) {
        foreach ($this->getFields() as $F) {
            if (in_array($F->get('id'), $ids)) {
                $F->disable();
            }
        }
    }

    function hasAnyVisibleFields($user=false) {
        global $thisstaff, $thisclient;
        $user = $user ?: $thisstaff ?: $thisclient;
        $visible = 0;
        $isstaff = $user instanceof Staff;
        foreach ($this->getFields() as $F) {
            if ($isstaff) {
                if ($F->isVisibleToStaff())
                    $visible++;
            }
            elseif ($F->isVisibleToUsers()) {
                $visible++;
            }
        }
        return $visible > 0;
    }

    function instanciate($sort=1) {
        return DynamicFormEntry::create(array(
            'form_id'=>$this->get('id'), 'sort'=>$sort));
    }

    function data($data) {
        if ($data instanceof DynamicFormEntry) {
            $this->_fields = $data->getFields();
            $this->_has_data = true;
        }
    }

    function getTranslateTag($subtag) {
        return _H(sprintf('form.%s.%s', $subtag, $this->id));
    }
    function getLocal($subtag) {
        $tag = $this->getTranslateTag($subtag);
        $T = CustomDataTranslation::translate($tag);
        return $T != $tag ? $T : $this->get($subtag);
    }

    function save($refetch=false) {
        if (count($this->dirty))
            $this->set('updated', new SqlFunction('NOW'));
        if (isset($this->dirty['notes']))
            $this->notes = Format::sanitize($this->notes);
        if ($rv = parent::save($refetch | $this->dirty))
            return $this->saveTranslations();
        return $rv;
    }

    function delete() {
        if (!$this->isDeletable())
            return false;
        else
            return parent::delete();
    }


    function getExportableFields($exclude=array()) {

        $fields = array();
        foreach ($this->getFields() as $f) {
            // Ignore core fields
            if ($exclude && in_array($f->get('name'), $exclude))
                continue;
            // Ignore non-data fields
            elseif (!$f->hasData() || $f->isPresentationOnly())
                continue;

            $fields['__field_'.$f->get('id')] = $f;
        }

        return $fields;
    }


    static function create($ht=false) {
        $inst = parent::create($ht);
        $inst->set('created', new SqlFunction('NOW'));
        if (isset($ht['fields'])) {
            $inst->save();
            foreach ($ht['fields'] as $f) {
                $f = DynamicFormField::create($f);
                $f->form_id = $inst->id;
                $f->setForm($inst);
                $f->save();
            }
        }
        return $inst;
    }

    function saveTranslations($vars=false) {
        global $thisstaff;

        $vars = $vars ?: $_POST;
        $tags = array(
            'title' => $this->getTranslateTag('title'),
            'instructions' => $this->getTranslateTag('instructions'),
        );
        $rtags = array_flip($tags);
        $translations = CustomDataTranslation::allTranslations($tags, 'phrase');
        foreach ($translations as $t) {
            $T = $rtags[$t->object_hash];
            $content = @$vars['trans'][$t->lang][$T];
            if (!isset($content))
                continue;

            // Content is not new and shouldn't be added below
            unset($vars['trans'][$t->lang][$T]);

            $t->text = $content;
            $t->agent_id = $thisstaff->getId();
            $t->updated = SqlFunction::NOW();
            if (!$t->save())
                return false;
        }
        // New translations (?)
        if ($vars['trans'] && is_array($vars['trans'])) {
            foreach ($vars['trans'] as $lang=>$parts) {
                if (!Internationalization::isLanguageInstalled($lang))
                    continue;
                foreach ($parts as $T => $content) {
                    $content = trim($content);
                    if (!$content)
                        continue;
                    $t = CustomDataTranslation::create(array(
                        'type'      => 'phrase',
                        'object_hash' => $tags[$T],
                        'lang'      => $lang,
                        'text'      => $content,
                        'agent_id'  => $thisstaff->getId(),
                        'updated'   => SqlFunction::NOW(),
                    ));
                    if (!$t->save())
                        return false;
                }
            }
        }
        return true;
    }


    static function getCrossTabQuery($object_type, $object_id='object_id', $exclude=array()) {
        $fields = static::getDynamicDataViewFields($exclude);
        return "SELECT entry.`object_id` as `$object_id`, ".implode(',', $fields)
            .' FROM '.FORM_ENTRY_TABLE.' entry
            JOIN '.FORM_ANSWER_TABLE.' ans ON ans.entry_id = entry.id
            JOIN '.FORM_FIELD_TABLE." field ON field.id=ans.field_id
            WHERE entry.object_type='$object_type' GROUP BY entry.object_id";
    }

    // Materialized View for Ticket custom data (MySQL FlexViews would be
    // nice)
    //
    // @see http://code.google.com/p/flexviews/
    static function getDynamicDataViewFields($exclude) {
        $fields = array();
        foreach (static::getInstance()->getFields() as $f) {
            if ($exclude && in_array($f->get('name'), $exclude))
                continue;

            $impl = $f->getImpl();
            if (!$impl->hasData() || $impl->isPresentationOnly())
                continue;

            $id = $f->get('id');
            $name = ($f->get('name')) ? $f->get('name')
                : 'field_'.$id;

            if ($impl instanceof ChoiceField || $impl instanceof SelectionField) {
                $fields[] = sprintf(
                    'MAX(CASE WHEN field.id=\'%1$s\' THEN REPLACE(REPLACE(REPLACE(REPLACE(coalesce(ans.value_id, ans.value), \'{\', \'\'), \'}\', \'\'), \'"\', \'\'), \':\', \',\') ELSE NULL END) as `%2$s`',
                    $id, $name);
            }
            else {
                $fields[] = sprintf(
                    'MAX(IF(field.id=\'%1$s\',coalesce(ans.value_id, ans.value),NULL)) as `%2$s`',
                    $id, $name);
            }
        }
        return $fields;
    }



}

class UserForm extends DynamicForm {
    static $instance;
    static $form;

    static function objects() {
        $os = parent::objects();
        return $os->filter(array('type'=>'U'));
    }

    static function getUserForm() {
        if (!isset(static::$form)) {
            static::$form = static::objects()->one();
        }
        return static::$form;
    }

    static function getInstance() {
        if (!isset(static::$instance))
            static::$instance = static::getUserForm()->instanciate();
        return static::$instance;
    }

    static function getNewInstance() {
        $o = static::objects()->one();
        static::$instance = $o->instanciate();
        return static::$instance;
    }
}
Filter::addSupportedMatches(/* @trans */ 'User Data', function() {
    $matches = array();
    foreach (UserForm::getInstance()->getFields() as $f) {
        if (!$f->hasData())
            continue;
        $matches['field.'.$f->get('id')] = __('User').' / '.$f->getLabel();
        if (($fi = $f->getImpl()) && $fi->hasSubFields()) {
            foreach ($fi->getSubFields() as $p) {
                $matches['field.'.$f->get('id').'.'.$p->get('id')]
                    = __('User').' / '.$f->getLabel().' / '.$p->getLabel();
            }
        }
    }
    return $matches;
}, 20);

class TicketForm extends DynamicForm {
    static $instance;

    static function objects() {
        $os = parent::objects();
        return $os->filter(array('type'=>'T'));
    }

    static function getInstance() {
        if (!isset(static::$instance))
            self::getNewInstance();
        return static::$instance;
    }

    static function getNewInstance() {
        $o = static::objects()->one();
        static::$instance = $o->instanciate();
        return static::$instance;
    }

    static function ensureDynamicDataView() {
        $sql = 'SHOW TABLES LIKE \''.TABLE_PREFIX.'ticket__cdata\'';
        if (!db_num_rows(db_query($sql)))
            return static::buildDynamicDataView();
    }

    static function buildDynamicDataView() {
        // create  table __cdata (primary key (ticket_id)) as select
        // entry.object_id as ticket_id, MAX(IF(field.name = 'subject',
        // ans.value, NULL)) as `subject`,MAX(IF(field.name = 'priority',
        // ans.value, NULL)) as `priority_desc`,MAX(IF(field.name =
        // 'priority', ans.value_id, NULL)) as `priority_id`
        // FROM ost_form_entry entry LEFT JOIN ost_form_entry_values ans ON
        // ans.entry_id = entry.id LEFT JOIN ost_form_field field ON
        // field.id=ans.field_id
        // where entry.object_type='T' group by entry.object_id;
        $sql = 'CREATE TABLE `'.TABLE_PREFIX.'ticket__cdata` (PRIMARY KEY
                (ticket_id)) AS ' . static::getCrossTabQuery('T', 'ticket_id');
        db_query($sql);
    }

    static function dropDynamicDataView() {
        db_query('DROP TABLE IF EXISTS `'.TABLE_PREFIX.'ticket__cdata`');
    }

    static function updateDynamicDataView($answer, $data) {
        // TODO: Detect $data['dirty'] for value and value_id
        // We're chiefly concerned with Ticket form answers
        if (!($e = $answer->getEntry()) || $e->getForm()->get('type') != 'T')
            return;

        // $record = array();
        // $record[$f] = $answer->value'
        // TicketFormData::objects()->filter(array('ticket_id'=>$a))
        //      ->merge($record);
        $sql = 'SHOW TABLES LIKE \''.TABLE_PREFIX.'ticket__cdata\'';
        if (!db_num_rows(db_query($sql)))
            return;

        $f = $answer->getField();
        $name = $f->get('name') ? $f->get('name')
            : 'field_'.$f->get('id');
        $fields = sprintf('`%s`=', $name) . db_input(
            implode(',', $answer->getSearchKeys()));
        $sql = 'INSERT INTO `'.TABLE_PREFIX.'ticket__cdata` SET '.$fields
            .', `ticket_id`='.db_input($answer->getEntry()->get('object_id'))
            .' ON DUPLICATE KEY UPDATE '.$fields;
        if (!db_query($sql) || !db_affected_rows())
            return self::dropDynamicDataView();
    }
}
// Add fields from the standard ticket form to the ticket filterable fields
Filter::addSupportedMatches(/* @trans */ 'Ticket Data', function() {
    $matches = array();
    foreach (TicketForm::getInstance()->getFields() as $f) {
        if (!$f->hasData())
            continue;
        $matches['field.'.$f->get('id')] = __('Ticket').' / '.$f->getLabel();
        if (($fi = $f->getImpl()) && $fi->hasSubFields()) {
            foreach ($fi->getSubFields() as $p) {
                $matches['field.'.$f->get('id').'.'.$p->get('id')]
                    = __('Ticket').' / '.$f->getLabel().' / '.$p->getLabel();
            }
        }
    }
    return $matches;
}, 30);
// Manage materialized view on custom data updates
Signal::connect('model.created',
    array('TicketForm', 'updateDynamicDataView'),
    'DynamicFormEntryAnswer');
Signal::connect('model.updated',
    array('TicketForm', 'updateDynamicDataView'),
    'DynamicFormEntryAnswer');
// Recreate the dynamic view after new or removed fields to the ticket
// details form
Signal::connect('model.created',
    array('TicketForm', 'dropDynamicDataView'),
    'DynamicFormField',
    function($o) { return $o->getForm()->get('type') == 'T'; });
Signal::connect('model.deleted',
    array('TicketForm', 'dropDynamicDataView'),
    'DynamicFormField',
    function($o) { return $o->getForm()->get('type') == 'T'; });
// If the `name` column is in the dirty list, we would be renaming a
// column. Delete the view instead.
Signal::connect('model.updated',
    array('TicketForm', 'dropDynamicDataView'),
    'DynamicFormField',
    // TODO: Lookup the dynamic form to verify {type == 'T'}
    function($o, $d) { return isset($d['dirty'])
        && (isset($d['dirty']['name']) || isset($d['dirty']['type'])); });

Filter::addSupportedMatches(/* trans */ 'Custom Forms', function() {
    $matches = array();
    foreach (DynamicForm::objects()->filter(array('type'=>'G')) as $form) {
        foreach ($form->getFields() as $f) {
            if (!$f->hasData())
                continue;
            $matches['field.'.$f->get('id')] = $form->getTitle().' / '.$f->getLabel();
            if (($fi = $f->getImpl()) && $fi->hasSubFields()) {
                foreach ($fi->getSubFields() as $p) {
                    $matches['field.'.$f->get('id').'.'.$p->get('id')]
                        = $form->getTitle().' / '.$f->getLabel().' / '.$p->getLabel();
                }
            }
        }
    }
    return $matches;
}, 9900);

require_once(INCLUDE_DIR . "class.json.php");

class DynamicFormField extends VerySimpleModel {

    static $meta = array(
        'table' => FORM_FIELD_TABLE,
        'ordering' => array('sort'),
        'pk' => array('id'),
        'select_related' => array('form'),
        'joins' => array(
            'form' => array(
                'null' => true,
                'constraint' => array('form_id' => 'DynamicForm.id'),
            ),
        ),
    );

    var $_field;
    var $_disabled = false;

    const FLAG_ENABLED          = 0x00001;
    const FLAG_EXT_STORED       = 0x00002; // Value stored outside of form_entry_value
    const FLAG_CLOSE_REQUIRED   = 0x00004;

    const FLAG_MASK_CHANGE      = 0x00010;
    const FLAG_MASK_DELETE      = 0x00020;
    const FLAG_MASK_EDIT        = 0x00040;
    const FLAG_MASK_DISABLE     = 0x00080;
    const FLAG_MASK_REQUIRE     = 0x10000;
    const FLAG_MASK_VIEW        = 0x20000;
    const FLAG_MASK_NAME        = 0x40000;

    const MASK_MASK_INTERNAL    = 0x400B0;  # !change, !delete, !disable, !edit-name
    const MASK_MASK_ALL         = 0x700F0;

    const FLAG_CLIENT_VIEW      = 0x00100;
    const FLAG_CLIENT_EDIT      = 0x00200;
    const FLAG_CLIENT_REQUIRED  = 0x00400;

    const MASK_CLIENT_FULL      = 0x00700;

    const FLAG_AGENT_VIEW       = 0x01000;
    const FLAG_AGENT_EDIT       = 0x02000;
    const FLAG_AGENT_REQUIRED   = 0x04000;

    const MASK_AGENT_FULL       = 0x7000;

    // Multiple inheritance -- delegate to FormField
    function __call($what, $args) {
        return call_user_func_array(
            array($this->getField(), $what), $args);
    }

    function getField($cache=true) {
        global $thisstaff;

        // Finagle the `required` flag for the FormField instance
        $ht = $this->ht;
        $ht['required'] = ($thisstaff) ? $this->isRequiredForStaff()
            : $this->isRequiredForUsers();

        if (!$cache)
            return new FormField($ht);

        if (!isset($this->_field))
            $this->_field = new FormField($ht);
        return $this->_field;
    }

    function getAnswer() { return $this->answer; }

    /**
     * setConfiguration
     *
     * Used in the POST request of the configuration process. The
     * ::getConfigurationForm() method should be used to retrieve a
     * configuration form for this field. That form should be submitted via
     * a POST request, and this method should be called in that request. The
     * data from the POST request will be interpreted and will adjust the
     * configuration of this field
     *
     * Parameters:
     * errors - (OUT array) receives validation errors of the parsed
     *      configuration form
     *
     * Returns:
     * (bool) true if the configuration was updated, false if there were
     * errors. If false, the errors were written into the received errors
     * array.
     */
    function setConfiguration(&$errors=array()) {
        $config = array();
        foreach ($this->getConfigurationForm($_POST)->getFields() as $name=>$field) {
            $config[$name] = $field->to_php($field->getClean());
            $errors = array_merge($errors, $field->errors());
        }
        if (count($errors) === 0)
            $this->set('configuration', JsonDataEncoder::encode($config));
        $this->set('hint', Format::sanitize($_POST['hint']));
        return count($errors) === 0;
    }

    function isDeletable() {
        return !$this->hasFlag(self::FLAG_MASK_DELETE);
    }
    function isNameForced() {
        return $this->hasFlag(self::FLAG_MASK_NAME);
    }
    function isPrivacyForced() {
        return $this->hasFlag(self::FLAG_MASK_VIEW);
    }
    function isRequirementForced() {
        return $this->hasFlag(self::FLAG_MASK_REQUIRE);
    }

    function  isChangeable() {
        return !$this->hasFlag(self::FLAG_MASK_CHANGE);
    }

    function  isEditable() {
        return $this->hasFlag(self::FLAG_MASK_EDIT);
    }
    function disable() {
        $this->_disabled = true;
    }
    function isEnabled() {
        return !$this->_disabled && $this->hasFlag(self::FLAG_ENABLED);
    }

    function hasFlag($flag) {
        return (isset($this->flags) && ($this->flags & $flag) != 0);
    }

    function getVisibilityDescription() {
        $F = $this->flags;

        if (!$this->hasFlag(self::FLAG_ENABLED))
            return __('Disabled');

        $impl = $this->getImpl();

        $hints = array();
        $VIEW = self::FLAG_CLIENT_VIEW | self::FLAG_AGENT_VIEW;
        if (($F & $VIEW) == 0) {
            $hints[] = __('Hidden');
        }
        elseif (~$F & self::FLAG_CLIENT_VIEW) {
            $hints[] = __('Internal');
        }
        elseif (~$F & self::FLAG_AGENT_VIEW) {
            $hints[] = __('For EndUsers Only');
        }
        if ($impl->hasData()) {
            if ($F & (self::FLAG_CLIENT_REQUIRED | self::FLAG_AGENT_REQUIRED)) {
                $hints[] = __('Required');
            }
            else {
                $hints[] = __('Optional');
            }
            if (!($F & (self::FLAG_CLIENT_EDIT | self::FLAG_AGENT_EDIT))) {
                $hints[] = __('Immutable');
            }
        }
        return implode(', ', $hints);
    }
    function getTranslateTag($subtag) {
        return _H(sprintf('field.%s.%s', $subtag, $this->id));
    }
    function getLocal($subtag, $default=false) {
        $tag = $this->getTranslateTag($subtag);
        $T = CustomDataTranslation::translate($tag);
        return $T != $tag ? $T : ($default ?: $this->get($subtag));
    }

    function allRequirementModes() {
        return array(
            'a' => array('desc' => __('Optional'),
                'flags' => self::FLAG_CLIENT_VIEW | self::FLAG_AGENT_VIEW
                    | self::FLAG_CLIENT_EDIT | self::FLAG_AGENT_EDIT),
            'b' => array('desc' => __('Required'),
                'flags' => self::FLAG_CLIENT_VIEW | self::FLAG_AGENT_VIEW
                    | self::FLAG_CLIENT_EDIT | self::FLAG_AGENT_EDIT
                    | self::FLAG_CLIENT_REQUIRED | self::FLAG_AGENT_REQUIRED),
            'c' => array('desc' => __('Required for EndUsers'),
                'flags' => self::FLAG_CLIENT_VIEW | self::FLAG_AGENT_VIEW
                    | self::FLAG_CLIENT_EDIT | self::FLAG_AGENT_EDIT
                    | self::FLAG_CLIENT_REQUIRED),
            'd' => array('desc' => __('Required for Agents'),
                'flags' => self::FLAG_CLIENT_VIEW | self::FLAG_AGENT_VIEW
                    | self::FLAG_CLIENT_EDIT | self::FLAG_AGENT_EDIT
                    | self::FLAG_AGENT_REQUIRED),
            'e' => array('desc' => __('Internal, Optional'),
                'flags' => self::FLAG_AGENT_VIEW | self::FLAG_AGENT_EDIT),
            'f' => array('desc' => __('Internal, Required'),
                'flags' => self::FLAG_AGENT_VIEW | self::FLAG_AGENT_EDIT
                    | self::FLAG_AGENT_REQUIRED),
            'g' => array('desc' => __('For EndUsers Only'),
                'flags' => self::FLAG_CLIENT_VIEW | self::FLAG_CLIENT_EDIT
                    | self::FLAG_CLIENT_REQUIRED),
        );
    }

    function getAllRequirementModes() {
        $modes = static::allRequirementModes();
        if ($this->isPrivacyForced()) {
            // Required to be internal
            foreach ($modes as $m=>$info) {
                if ($info['flags'] & (self::FLAG_CLIENT_VIEW | self::FLAG_AGENT_VIEW))
                    unset($modes[$m]);
            }
        }

        if ($this->isRequirementForced()) {
            // Required to be required
            foreach ($modes as $m=>$info) {
                if ($info['flags'] & (self::FLAG_CLIENT_REQUIRED | self::FLAG_AGENT_REQUIRED))
                    unset($modes[$m]);
            }
        }
        return $modes;
    }

    function setRequirementMode($mode) {
        $modes = $this->getAllRequirementModes();
        if (!isset($modes[$mode]))
            return false;

        $info = $modes[$mode];
        $this->set('flags', $info['flags'] | self::FLAG_ENABLED);
    }

    function isRequiredForStaff() {
        return $this->hasFlag(self::FLAG_AGENT_REQUIRED);
    }
    function isRequiredForUsers() {
        return $this->hasFlag(self::FLAG_CLIENT_REQUIRED);
    }
    function isRequiredForClose() {
        return $this->hasFlag(self::FLAG_CLOSE_REQUIRED);
    }
    function isEditableToStaff() {
        return $this->isEnabled()
            && $this->hasFlag(self::FLAG_AGENT_EDIT);
    }
    function isVisibleToStaff() {
        return $this->isEnabled()
            && $this->hasFlag(self::FLAG_AGENT_VIEW);
    }
    function isEditableToUsers() {
        return $this->isEnabled()
            && $this->hasFlag(self::FLAG_CLIENT_EDIT);
    }
    function isVisibleToUsers() {
        return $this->isEnabled()
            && $this->hasFlag(self::FLAG_CLIENT_VIEW);
    }

    /**
     * Used when updating the form via the admin panel. This represents
     * validation on the form field template, not data entered into a form
     * field of a custom form. The latter would be isValidEntry()
     */
    function isValid() {
        if (count($this->errors()))
            return false;
        if (!$this->get('label'))
            $this->addError(
                __("Label is required for custom form fields"), "label");
        if ($this->get('required') && !$this->get('name'))
            $this->addError(
                __("Variable name is required for required fields"
                /* `required` is a visibility setting fields */
                /* `variable` is used for automation. Internally it's called `name` */
                ), "name");
        if (preg_match('/[.{}\'"`; ]/u', $this->get('name')))
            $this->addError(__(
                'Invalid character in variable name. Please use letters and numbers only.'
            ), 'name');
        return count($this->errors()) == 0;
    }

    function delete() {
        // Don't really delete form fields as that will screw up the data
        // model. Instead, just drop the association with the form which
        // will give the appearance of deletion. Not deleting means that
        // the field will continue to exist on form entries it may already
        // have answers on, but since it isn't associated with the form, it
        // won't be available for new form submittals.
        $this->set('form_id', 0);
        $this->save();
    }

    function save($refetch=false) {
        if (count($this->dirty))
            $this->set('updated', new SqlFunction('NOW'));
        return parent::save($this->dirty || $refetch);
    }

    static function create($ht=false) {
        $inst = parent::create($ht);
        $inst->set('created', new SqlFunction('NOW'));
        if (isset($ht['configuration']))
            $inst->configuration = JsonDataEncoder::encode($ht['configuration']);
        return $inst;
    }
}

/**
 * Represents an entry to a dynamic form. Used to render the completed form
 * in reference to the attached ticket, etc. A form is used to represent the
 * template of enterable data. This represents the data entered into an
 * instance of that template.
 *
 * The data of the entry is called 'answers' in this model. This model
 * represents an instance of a form entry. The data / answers to that entry
 * are represented individually in the DynamicFormEntryAnswer model.
 */
class DynamicFormEntry extends VerySimpleModel {

    static $meta = array(
        'table' => FORM_ENTRY_TABLE,
        'ordering' => array('sort'),
        'pk' => array('id'),
        'select_related' => array('form'),
        'joins' => array(
            'form' => array(
                'null' => true,
                'constraint' => array('form_id' => 'DynamicForm.id'),
            ),
        ),
    );

    var $_values;
    var $_fields;
    var $_form;
    var $_errors = false;
    var $_clean = false;
    var $_source = null;

    function getId() {
        return $this->get('id');
    }

    function getAnswers() {
        if (!isset($this->_values)) {
            $this->_values = DynamicFormEntryAnswer::objects()
                ->filter(array('entry_id'=>$this->get('id')))
                ->all();
            foreach ($this->_values as $v)
                $v->entry = $this;
        }
        return $this->_values;
    }

    function getAnswer($name) {
        foreach ($this->getAnswers() as $ans)
            if ($ans->getField()->get('name') == $name)
                return $ans;
        return null;
    }

    function setAnswer($name, $value, $id=false) {
        foreach ($this->getAnswers() as $ans) {
            $f = $ans->getField();
            if ($f->isStorable() && $f->get('name') == $name) {
                $f->reset();
                $ans->set('value', $value);
                if ($id !== false)
                    $ans->set('value_id', $id);
                break;
            }
        }
    }

    function errors() {
        return $this->_errors;
    }

    function getTitle() { return $this->getForm()->getTitle(); }
    function getInstructions() { return $this->getForm()->getInstructions(); }

    function getForm() {
        if (!isset($this->_form)) {
            $this->_form = DynamicForm::lookup($this->get('form_id'));
            if ($this->_form && isset($this->id))
                $this->_form->data($this);
            if (isset($this->extra)) {
                $x = JsonDataParser::decode($this->extra) ?: array();
                $this->_form->disableFields($x['disable'] ?: array());
            }
        }
        return $this->_form;
    }

    function getFields() {
        if (!isset($this->_fields)) {
            $this->_fields = array();
            foreach ($this->getAnswers() as $a) {
                $T = $this->_fields[] = $a->getField();
                $T->setForm($this);
            }
        }
        return $this->_fields;
    }

    function getSource() {
        return $this->_source ?: (isset($this->id) ? false : $_POST);
    }
    function setSource($source) {
        $this->_source = $source;
    }

    function getField($name) {

        foreach ($this->getFields() as $field)
            if (!strcasecmp($field->get('name'), $name))
                return $field;

        return null;
    }

    /**
     * Validate the form and indicate if there no errors.
     *
     * Parameters:
     * $filter - (callback) function to receive each field and return
     *      boolean true if the field's errors are significant
     */
    function isValid($filter=false) {
        if (!is_array($this->_errors)) {
            $this->_errors = array();
            $this->getClean();
            foreach ($this->getFields() as $field) {
                if ($field->errors() && (!$filter || $filter($field)))
                    $this->_errors[$field->get('id')] = $field->errors();
            }
        }
        return !$this->_errors;
    }

    function isValidForClient() {
        $filter = function($f) {
            return $f->isVisibleToUsers();
        };
        return $this->isValid($filter);
    }

    function isValidForStaff() {
        $filter = function($f) {
            return $f->isVisibleToStaff();
        };
        return $this->isValid($filter);
    }

    function getClean() {
        if (!$this->_clean) {
            $this->_clean = array();
            foreach ($this->getFields() as $field)
                $this->_clean[$field->get('id')]
                    = $this->_clean[$field->get('name')] = $field->getClean();
        }
        return $this->_clean;
    }

    /**
     * Compile a list of data used by the filtering system to match dynamic
     * content in this entry. This returs an array of `field.<id>` =>
     * <value> pairs where the <id> is the field id and the <value> is the
     * toString() value for the entered data.
     *
     * If the field returns an array for its ::getFilterData() method, the
     * data will be added in the array with the keys prefixed with
     * `field.<id>`. This is useful for properties on custom lists, for
     * instance, which can contain properties usefule for matching and
     * filtering.
     */
    function getFilterData() {
        $vars = array();
        foreach ($this->getFields() as $f) {
            $tag = 'field.'.$f->get('id');
            if ($d = $f->getFilterData()) {
                if (is_array($d)) {
                    foreach ($d as $k=>$v) {
                        if (is_string($k))
                            $vars["$tag$k"] = $v;
                        else
                            $vars[$tag] = $v;
                    }
                }
                else {
                    $vars[$tag] = $d;
                }
            }
        }
        return $vars;
    }

    function getSaved() {
        $info = array();
        foreach ($this->getAnswers() as $a) {
            $field = $a->getField();
            $info[$field->get('id')]
                = $info[$field->get('name')] = $a->getValue();
        }
        return $info;
    }

    function hasAnyVisibleFields($user=false) {
        global $thisstaff, $thisclient;
        $user = $user ?: $thisstaff ?: $thisclient;
        $visible = 0;
        $isstaff = $user instanceof Staff;
        foreach ($this->getFields() as $F) {
            if ($isstaff) {
                if ($F->isVisibleToStaff())
                    $visible++;
            }
            elseif ($F->isVisibleToUsers()) {
                $visible++;
            }
        }
        return $visible > 0;
    }

    function forTicket($ticket_id, $force=false) {
        static $entries = array();
        if (!isset($entries[$ticket_id]) || $force) {
            $stuff = DynamicFormEntry::objects()
                ->filter(array('object_id'=>$ticket_id, 'object_type'=>'T'));
            // If forced, don't cache the result
            if ($force)
                return $stuff;
            $entries[$ticket_id] = &$stuff;
        }
        return $entries[$ticket_id];
    }
    function setTicketId($ticket_id) {
        $this->object_type = 'T';
        $this->object_id = $ticket_id;
    }

    function setClientId($user_id) {
        $this->object_type = 'U';
        $this->object_id = $user_id;
    }

    function setObjectId($object_id) {
        $this->object_id = $object_id;
    }

    function forObject($object_id, $object_type) {
        return DynamicFormEntry::objects()
            ->filter(array('object_id'=>$object_id, 'object_type'=>$object_type));
    }

    function render($staff=true, $title=false, $options=array()) {
        return $this->getForm()->render($staff, $title, $options);
    }

    /**
     * addMissingFields
     *
     * Adds fields that have been added to the linked form (field set) since
     * this entry was originally created. If fields are added to the form,
     * the method will automatically add the fields and null answers to the
     * entry.
     */
    function addMissingFields() {
        // Track deletions
        foreach ($this->getAnswers() as $answer)
            $answer->deleted = true;

        foreach ($this->getForm()->getDynamicFields() as $field) {
            $found = false;
            foreach ($this->getAnswers() as $answer) {
                if ($answer->get('field_id') == $field->get('id')) {
                    $answer->deleted = false; $found = true; break;
                }
            }
            if (!$found && ($fImpl = $field->getImpl($field))
                    && $field->isEnabled()
                    && !$fImpl->isPresentationOnly()) {
                $a = DynamicFormEntryAnswer::create(
                    array('field_id'=>$field->get('id'), 'entry_id'=>$this->id));
                $a->field = $field;
                $a->entry = $this;
                $a->deleted = false;
                // Add to list of answers
                $this->_values[] = $a;
                $this->_fields[] = $fImpl;
                $this->_form = null;

                // Omit fields without data and non-storable fields.
                if (!$field->hasData() || !$field->isStorable())
                    continue;

                $a->save();
            }
            // Sort the form the way it is declared to be sorted
            if ($this->_fields)
                usort($this->_fields,
                    function($a, $b) {
                        return $a->get('sort') - $b->get('sort');
                });
        }
    }

    function save() {
        if (count($this->dirty))
            $this->set('updated', new SqlFunction('NOW'));
        parent::save();
        foreach ($this->getFields() as $field) {
            if (!$field->isStorable())
                continue;

            $a = $field->getAnswer();
            // Set the entry ID here so that $field->getClean() can use the
            // entry-id if necessary
            $a->set('entry_id', $this->get('id'));
            $val = $field->to_database($field->getClean());
            if (is_array($val)) {
                $a->set('value', $val[0]);
                $a->set('value_id', $val[1]);
            }
            else
                $a->set('value', $val);
            // Don't save answers for presentation-only fields
            if ($field->hasData() && !$field->isPresentationOnly())
                $a->save();
        }
        $this->_values = null;
    }

    function delete() {
        foreach ($this->getAnswers() as $a)
            $a->delete();
        return parent::delete();
    }

    static function create($ht=false) {
        $inst = parent::create($ht);
        $inst->set('created', new SqlFunction('NOW'));
        foreach ($inst->getForm()->getDynamicFields() as $f) {
            if (!$f->hasData()) continue;
            $a = DynamicFormEntryAnswer::create(
                array('field_id'=>$f->get('id')));
            $a->field = $f;
            $a->field->setAnswer($a);
            $a->entry = $inst;
            $inst->_values[] = $a;
        }
        return $inst;
    }
}

/**
 * Represents a single answer to a single field on a dynamic form. The
 * data / answer to the field is linked back to the form and field which was
 * originally used for the submission.
 */
class DynamicFormEntryAnswer extends VerySimpleModel {

    static $meta = array(
        'table' => FORM_ANSWER_TABLE,
        'ordering' => array('field__sort'),
        'pk' => array('entry_id', 'field_id'),
        'select_related' => array('field'),
        'fields' => array('entry_id', 'field_id', 'value', 'value_id'),
        'joins' => array(
            'field' => array(
                'constraint' => array('field_id' => 'DynamicFormField.id'),
            ),
            'entry' => array(
                'constraint' => array('entry_id' => 'DynamicFormEntry.id'),
            ),
        ),
    );

    var $_field;
    var $form;
    var $entry;
    var $deleted = false;
    var $_value;

    function getEntry() {
        return $this->entry;
    }

    function getForm() {
        if (!$this->form)
            $this->form = $this->getEntry()->getForm();
        return $this->form;
    }

    function getField() {
        if (!isset($this->_field)) {
            $this->_field = $this->field->getImpl($this->field);
            $this->_field->setAnswer($this);
        }
        return $this->_field;
    }

    function getValue() {
        if (!isset($this->_value) && isset($this->value)) {
            //XXX: We're settting the value here to avoid infinite loop
            $this->_value = false;
            $this->_value = $this->getField()->to_php(
                $this->get('value'), $this->get('value_id'));
        }

        return $this->_value;
    }

    function getLocal($tag) {
        return $this->field->getLocal($tag);
    }

    function getIdValue() {
        return $this->get('value_id');
    }

    function isDeleted() {
        return $this->deleted;
    }

    function toString() {
        return $this->getField()->toString($this->getValue());
    }

    function display() {
        return $this->getField()->display($this->getValue());
    }

    function getSearchable($include_label=false) {
        if ($include_label)
            $label = Format::searchable($this->getField()->getLabel()) . " ";
        return sprintf("%s%s", $label,
            $this->getField()->searchable($this->getValue())
        );
    }

    function getSearchKeys() {
        $val = $this->getField()->to_php(
            $this->get('value'), $this->get('value_id'));
        if (is_array($val))
            return array_keys($val);
        elseif (is_object($val) && method_exists($val, 'getId'))
            return array($val->getId());

        return array($val);
    }

    function asVar() {
        return (is_object($this->getValue()))
            ? $this->getValue() : $this->toString();
    }

    function getVar($tag) {
        if (is_object($this->getValue()) && method_exists($this->getValue(), 'getVar'))
            return $this->getValue()->getVar($tag);
    }

    function __toString() {
        $v = $this->toString();
        return is_string($v) ? $v : (string) $this->getValue();
    }
}

class SelectionField extends FormField {
    static $widget = 'ChoicesWidget';

    function getListId() {
        list(,$list_id) = explode('-', $this->get('type'));
        return $list_id ?: $this->get('list_id');
    }

    function getList() {
        if (!$this->_list)
            $this->_list = DynamicList::lookup($this->getListId());

        return $this->_list;
    }

    function getWidget() {
        $config = $this->getConfiguration();
        $widgetClass = false;
        if ($config['widget'] == 'typeahead' && $config['multiselect'] == false)
            $widgetClass = 'TypeaheadSelectionWidget';
        return parent::getWidget($widgetClass);
    }

    function parse($value) {

        if (!($list=$this->getList()))
            return null;

        $config = $this->getConfiguration();
        $choices = $this->getChoices();
        $selection = array();
        if ($value && is_array($value)) {
            foreach ($value as $k=>$v) {
                if (($i=$list->getItem((int) $k)))
                    $selection[$i->getId()] = $i->getValue();
                elseif (isset($choices[$v]))
                    $selection[$v] = $choices[$v];
            }
        }

        // Don't return an empty array
        return $selection ?: null;
    }

    function to_database($value) {
        if (is_array($value)) {
            reset($value);
        }
        if ($value && is_array($value))
            $value = JsonDataEncoder::encode($value);

        return $value;
    }

    function to_php($value, $id=false) {
        if (is_string($value))
            $value = JsonDataParser::parse($value) ?: $value;

        if (!is_array($value)) {
            $values = array();
            $choices = $this->getChoices();
            foreach (explode(',', $value) as $V) {
                if (isset($choices[$V]))
                    $values[$V] = $choices[$V];
            }
            if ($id && isset($choices[$id]))
                $values[$id] = $choices[$id];

            if ($values)
                return $values;
            // else return $value unchanged
        }
        // Don't set the ID here as multiselect prevents using exactly one
        // ID value. Instead, stick with the JSON value only.
        return $value;
    }

    function hasSubFields() {
        return $this->getList()->getForm();
    }
    function getSubFields() {
        $fields = new ListObject(array(
            new TextboxField(array(
                // XXX: i18n: Change to a better word when the UI changes
                'label' => '['.__('Abbrev').']',
                'id' => 'abb',
            ))
        ));
        $form = $this->getList()->getForm();
        if ($form && ($F = $form->getFields()))
            $fields->extend($F);
        return $fields;
    }

    function toString($items) {
        return ($items && is_array($items))
            ? implode(', ', $items) : (string) $items;
    }

    function validateEntry($entry) {
        parent::validateEntry($entry);
        if (!$this->errors()) {
            $config = $this->getConfiguration();
            if ($config['typeahead']
                    && ($entered = $this->getWidget()->getEnteredValue())
                    && !in_array($entered, $entry))
                $this->_errors[] = __('Select a value from the list');
        }
    }

    function getConfigurationOptions() {
        return array(
            'multiselect' => new BooleanField(array(
                'id'=>2,
                'label'=>__(/* Type of widget allowing multiple selections */ 'Multiselect'),
                'required'=>false, 'default'=>false,
                'configuration'=>array(
                    'desc'=>__('Allow multiple selections')),
            )),
            'widget' => new ChoiceField(array(
                'id'=>1,
                'label'=>__('Widget'),
                'required'=>false, 'default' => 'dropdown',
                'choices'=>array(
                    'dropdown' => __('Drop Down'),
                    'typeahead' =>__('Typeahead'),
                ),
                'configuration'=>array(
                    'multiselect' => false,
                ),
                'visibility' => new VisibilityConstraint(
                    new Q(array('multiselect__eq'=>false)),
                    VisibilityConstraint::HIDDEN
                ),
                'hint'=>__('Typeahead will work better for large lists')
            )),
            'prompt' => new TextboxField(array(
                'id'=>3,
                'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40,
                    'translatable'=>$this->getTranslateTag('prompt'),
                ),
            )),
            'default' => new SelectionField(array(
                'id'=>4, 'label'=>__('Default'), 'required'=>false, 'default'=>'',
                'list_id'=>$this->getListId(),
                'configuration' => array('prompt'=>__('Select a Default')),
            )),
        );
    }

    function getConfiguration() {

        $config = parent::getConfiguration();
        if ($config['widget'])
            $config['typeahead'] = $config['widget'] == 'typeahead';

        // Drop down list does not support multiple selections
        if ($config['typeahead'])
            $config['multiselect'] = false;

        return $config;
    }

    function getChoices($verbose=false) {
        if (!$this->_choices || $verbose) {
            $this->_choices = array();
            foreach ($this->getList()->getItems() as $i)
                $this->_choices[$i->getId()] = $i->getValue();

            // Retired old selections
            $values = ($a=$this->getAnswer()) ? $a->getValue() : array();
            if ($values && is_array($values)) {
                foreach ($values as $k => $v) {
                    if (!isset($this->_choices[$k])) {
                        if ($verbose) $v .= ' '.__('(retired)');
                        $this->_choices[$k] = $v;
                    }
                }
            }
        }
        return $this->_choices;
    }

    function getChoice($value) {
        $choices = $this->getChoices();
        if ($value && is_array($value)) {
            $selection = $value;
        } elseif (isset($choices[$value]))
            $selection[] = $choices[$value];
        elseif ($this->get('default'))
            $selection[] = $choices[$this->get('default')];

        return $selection;
    }

    function getFilterData() {
        // Start with the filter data for the list item as the [0] index
        $data = array(parent::getFilterData());
        if (($v = $this->getClean())) {
            // Add in the properties for all selected list items in sub
            // labeled by their field id
            foreach ($v as $id=>$L) {
                if (!($li = DynamicListItem::lookup($id)))
                    continue;
                foreach ($li->getFilterData() as $prop=>$value) {
                    if (!isset($data[$prop]))
                        $data[$prop] = $value;
                    else
                        $data[$prop] .= " $value";
                }
            }
        }
        return $data;
    }

    function getSearchMethods() {
        return array(
            'set' =>        __('has a value'),
            'notset' =>     __('does not have a value'),
            'includes' =>   __('includes'),
            '!includes' =>  __('does not include'),
        );
    }

    function getSearchMethodWidgets() {
        return array(
            'set' => null,
            'notset' => null,
            'includes' => array('ChoiceField', array(
                'choices' => $this->getChoices(),
                'configuration' => array('multiselect' => true),
            )),
            '!includes' => array('ChoiceField', array(
                'choices' => $this->getChoices(),
                'configuration' => array('multiselect' => true),
            )),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        $name = $name ?: $this->get('name');
        switch ($method) {
        case '!includes':
            return Q::not(array("{$name}__intersect" => array_keys($value)));
        case 'includes':
            return new Q(array("{$name}__intersect" => array_keys($value)));
        default:
            return parent::getSearchQ($method, $value, $name);
        }
    }
}

class TypeaheadSelectionWidget extends ChoicesWidget {
    function render($options=array()) {

        if ($options['mode'] == 'search')
            return parent::render($options);

        $name = $this->getEnteredValue();
        $config = $this->field->getConfiguration();
        if (is_array($this->value)) {
            $name = $name ?: current($this->value);
            $value = key($this->value);
        }
        else {
            // Pull configured default (if configured)
            $def_key = $this->field->get('default');
            if (!$def_key && $config['default'])
                $def_key = $config['default'];
            if (is_array($def_key))
                $name = current($def_key);
        }

        $source = array();
        foreach ($this->field->getList()->getItems() as $i)
            $source[] = array(
                'value' => $i->getValue(), 'id' => $i->getId(),
                'info' => sprintf('%s%s',
                    $i->getValue(),
                    (($extra= $i->getAbbrev()) ? " — $extra" : '')),
            );
        ?>
        <span style="display:inline-block">
        <input type="text" size="30" name="<?php echo $this->name; ?>_name"
            id="<?php echo $this->name; ?>" value="<?php echo Format::htmlchars($name); ?>"
            placeholder="<?php echo $config['prompt'];
            ?>" autocomplete="off" />
        <input type="hidden" name="<?php echo $this->name;
            ?>[<?php echo $value; ?>]" id="<?php echo $this->name;
            ?>_id" value="<?php echo Format::htmlchars($name); ?>"/>
        <script type="text/javascript">
        $(function() {
            $('input#<?php echo $this->name; ?>').typeahead({
                source: <?php echo JsonDataEncoder::encode($source); ?>,
                property: 'info',
                onselect: function(item) {
                    $('input#<?php echo $this->name; ?>_name').val(item['value'])
                    $('input#<?php echo $this->name; ?>_id')
                      .attr('name', '<?php echo $this->name; ?>[' + item['id'] + ']')
                      .val(item['value']);
                    return false;
                }
            });
        });
        </script>
        </span>
        <?php
    }

    function getValue() {
        $data = $this->field->getSource();
        if (isset($data[$this->name]))
            return $data[$this->name];
        return parent::getValue();
    }

    function getEnteredValue() {
        // Used to verify typeahead fields
        $data = $this->field->getSource();
        if (isset($data[$this->name.'_name'])) {
            // Drop the extra part, if any
            $v = $data[$this->name.'_name'];
            $v = substr($v, 0, strrpos($v, ' — '));
            return trim($v);
        }
        return parent::getValue();
    }
}
?>
