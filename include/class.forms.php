<?php
/*********************************************************************
    class.forms.php

    osTicket forms framework

    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

/**
 * Form template, used for designing the custom form and for entering custom
 * data for a ticket
 */
class Form {
    var $fields = array();
    var $title = '';
    var $instructions = '';
    var $item = null;
    
    var $validators = array();

    var $_errors = null;
    var $_source = false;

    function __construct($fields=array(), $source=null, $options=array()) {
        $this->fields = $fields;
        foreach ($fields as $k=>$f) {
            $f->setForm($this);
            if (!$f->get('name') && $k)
                $f->set('name', $k);
        }
        if (isset($options['title']))
            $this->title = $options['title'];
        if (isset($options['instructions']))
            $this->instructions = $options['instructions'];
        // Use POST data if source was not specified
        $this->_source = ($source) ? $source : $_POST;
    }
    function data($source) {
        foreach ($this->fields as $name=>$f)
            if (isset($source[$name]))
                $f->value = $source[$name];
    }

    function getFields() {
        return $this->fields;
    }

    function getField($name) {
        $fields = $this->getFields();
        foreach($fields as $f)
            if(!strcasecmp($f->get('name'), $name))
                return $f;
        if (isset($fields[$name]))
            return $fields[$name];
    }

    function getTitle() { return $this->title; }
    function getInstructions() { return $this->instructions; }
    function getItem() { return $this->item; }
    function getSource() { return $this->_source; }
    function setSource($source) { $this->_source = $source; }

    /**
     * Validate the form and indicate if there no errors.
     *
     * Parameters:
     * $filter - (callback) function to receive each field and return
     *      boolean true if the field's errors are significant
     */
    function isValid($include=false) {
        if (!isset($this->_errors)) {
            $this->_errors = array();
            $this->getClean();
            // Validate the whole form so that errors can be added to the
            // individual fields and collected below.
            foreach ($this->validators as $V) {
                $V($this);
            }
            foreach ($this->getFields() as $field)
                if ($field->errors() && (!$include || $include($field)))
                    $this->_errors[$field->get('id')] = $field->errors();
        }
        return !$this->_errors;
    }

    function getClean() {
        if (!$this->_clean) {
            $this->_clean = array();
            foreach ($this->getFields() as $key=>$field) {
                if (!$field->hasData())
                    continue;

                $this->_clean[$key] = $this->_clean[$field->get('name')]
                    = $field->getClean();
            }
            unset($this->_clean[""]);
        }
        return $this->_clean;
    }

    function errors($formOnly=false) {
        return ($formOnly) ? $this->_errors['form'] : $this->_errors;
    }

    function addError($message) {
        $this->_errors['form'][] = $message;
    }

    function addValidator($function) {
        if (!is_callable($function))
            throw new Exception('Form validator must be callable');
        $this->validators[] = $function;
    }

    function render($staff=true, $title=false, $options=array()) {
        if ($title)
            $this->title = $title;
        if (isset($options['instructions']))
            $this->instructions = $options['instructions'];
        $form = $this;
        if ($staff)
            include(STAFFINC_DIR . 'templates/dynamic-form.tmpl.php');
        else
            include(CLIENTINC_DIR . 'templates/dynamic-form.tmpl.php');
    }

    function getMedia() {
        static $dedup = array();

        foreach ($this->getFields() as $f) {
            if (($M = $f->getMedia()) && is_array($M)) {
                foreach ($M as $type=>$files) {
                    foreach ($files as $url) {
                        $key = strtolower($type.$url);
                        if (isset($dedup[$key]))
                            continue;

                        self::emitMedia($url, $type);

                        $dedup[$key] = true;
                    }
                }
            }
        }
    }

    static function emitMedia($url, $type) {
        if ($url[0] == '/')
            $url = ROOT_PATH . substr($url, 1);

        switch (strtolower($type)) {
        case 'css': ?>
        <link rel="stylesheet" type="text/css" href="<?php echo $url; ?>"/><?php
            break;
        case 'js': ?>
        <script type="text/javascript" src="<?php echo $url; ?>"></script><?php
            break;
        }
    }

    /**
     * getState
     *
     * Retrieves an array of information which can be passed to the
     * ::loadState method later to recreate the current state of the form
     * fields and values.
     */
    function getState() {
        $info = array();
        foreach ($this->getFields() as $f) {
            // Skip invisible fields
            if (!$f->isVisible())
                continue;

            // Skip fields set to default values
            $v = $f->getClean();
            $d = $f->get('default');
            if ($v == $d)
                continue;

            // Skip empty values
            if (!$v)
                continue;

            $info[$f->get('name') ?: $f->get('id')] = $f->to_database($v);
        }
        return $info;
    }

    /**
     * loadState
     *
     * Reset this form to the state previously recorded by the ::getState()
     * method
     */
    function loadState($state) {
        foreach ($this->getFields() as $f) {
            $name = $f->get('name');
            $f->reset();
            if (isset($state[$name])) {
                $f->value = $f->to_php($state[$name]);
            }
        }
    }
}

require_once(INCLUDE_DIR . "class.json.php");

class FormField {
    static $widget = false;

    var $ht = array(
        'label' => false,
        'required' => false,
        'default' => false,
        'configuration' => array(),
    );

    var $_form;
    var $_cform;
    var $_clean;
    var $_errors = array();
    var $_widget;
    var $answer;
    var $parent;
    var $presentation_only = false;

    static $types = array(
        /* @trans */ 'Basic Fields' => array(
            'text'  => array(   /* @trans */ 'Short Answer', 'TextboxField'),
            'memo' => array(    /* @trans */ 'Long Answer', 'TextareaField'),
            'thread' => array(  /* @trans */ 'Thread Entry', 'ThreadEntryField', false),
            'datetime' => array(/* @trans */ 'Date and Time', 'DatetimeField'),
            'phone' => array(   /* @trans */ 'Phone Number', 'PhoneField'),
            'bool' => array(    /* @trans */ 'Checkbox', 'BooleanField'),
            'choices' => array( /* @trans */ 'Choices', 'ChoiceField'),
            'files' => array(   /* @trans */ 'File Upload', 'FileUploadField'),
            'break' => array(   /* @trans */ 'Section Break', 'SectionBreakField'),
            'info' => array(    /* @trans */ 'Information', 'FreeTextField'),
        ),
    );
    static $more_types = array();
    static $uid = null;

    function __construct($options=array()) {
        $this->ht = array_merge($this->ht, $options);
        if (!isset($this->ht['id']))
            $this->ht['id'] = self::$uid++;
    }

    function __clone() {
        $this->_widget = null;
        $this->ht['id'] = self::$uid++;
    }

    static function addFieldTypes($group, $callable) {
        static::$more_types[$group][] = $callable;
    }

    static function allTypes() {
        if (static::$more_types) {
            foreach (static::$more_types as $group => $entries)
                foreach ($entries as $c)
                    static::$types[$group] = array_merge(
                            static::$types[$group] ?: array(), call_user_func($c));

            static::$more_types = array();
        }
        return static::$types;
    }

    static function getFieldType($type) {
        foreach (static::allTypes() as $group=>$types)
            if (isset($types[$type]))
                return $types[$type];
    }

    function get($what, $default=null) {
        return array_key_exists($what, $this->ht)
            ? $this->ht[$what]
            : $default;
    }
    function set($field, $value) {
        $this->ht[$field] = $value;
    }

    /**
     * getClean
     *
     * Validates and cleans inputs from POST request. This is performed on a
     * field instance, after a DynamicFormSet / DynamicFormSection is
     * submitted via POST, in order to kick off parsing and validation of
     * user-entered data.
     */
    function getClean() {
        if (!isset($this->_clean)) {
            $this->_clean = (isset($this->value))
                ? $this->value : $this->parse($this->getWidget()->value);

            if ($vs = $this->get('cleaners')) {
                if (is_array($vs)) {
                    foreach ($vs as $cleaner)
                        if (is_callable($cleaner))
                            $this->_clean = call_user_func_array(
                                    $cleaner, array($this, $this->_clean));
                }
                elseif (is_callable($vs))
                    $this->_clean = call_user_func_array(
                            $vs, array($this, $this->_clean));
            }

            if ($this->isVisible())
                $this->validateEntry($this->_clean);

            if (!isset($this->_clean) && ($d = $this->get('default')))
                $this->_clean = $d;
        }
        return $this->_clean;
    }
    function reset() {
        $this->_clean = $this->_widget = null;
    }

    function errors() {
        return $this->_errors;
    }
    function addError($message, $field=false) {
        if ($field)
            $this->_errors[$field] = $message;
        else
            $this->_errors[] = $message;
    }

    function isValidEntry() {
        $this->validateEntry();
        return count($this->_errors) == 0;
    }

    /**
     * validateEntry
     *
     * Validates user entry on an instance of the field on a dynamic form.
     * This is called when an instance of this field (like a TextboxField)
     * receives data from the user and that value should be validated.
     *
     * Parameters:
     * $value - (string) input from the user
     */
    function validateEntry($value) {
        if (!$value && count($this->_errors))
            return;

        # Validates a user-input into an instance of this field on a dynamic
        # form
        if ($this->get('required') && !$value && $this->hasData())
            $this->_errors[] = sprintf(__('%s is a required field'),
                $this->getLabel());

        # Perform declared validators for the field
        if ($vs = $this->get('validators')) {
            if (is_array($vs)) {
                foreach ($vs as $validator)
                    if (is_callable($validator))
                        $validator($this, $value);
            }
            elseif (is_callable($vs))
                $vs($this, $value);
        }
    }

    /**
     * isVisible
     *
     * If this field has visibility configuration, then it will parse the
     * constraints with the visibility configuration to determine if the
     * field is visible and should be considered for validation
     */
    function isVisible() {
        $config = $this->getConfiguration();
        if ($this->get('visibility') instanceof VisibilityConstraint) {
            return $this->get('visibility')->isVisible($this);
        }
        return true;
    }

    /**
     * FIXME: Temp
     *
     */

    function isEditable() {
        return (($this->get('edit_mask') & 32) == 0);
    }

    /**
     * isStorable
     *
     * Indicate if this field data is storable locally (default).Some field's data
     * might beed to be stored elsewhere for optimization reasons at the
     * application level.
     *
     */

    function isStorable() {
        return (($this->get('flags') & DynamicFormField::FLAG_EXT_STORED) == 0);
    }

    function isRequired() {
        return $this->get('required');
    }

    /**
     * isTranslatable
     * 
     *  Indicates if this field can be input in several languages
     */
    function isTranslatable() {
        return false;
    }

    /**
     * parse
     *
     * Used to transform user-submitted data to a PHP value. This value is
     * not yet considered valid. The ::validateEntry() method will be called
     * on the value to determine if the entry is valid. Therefore, if the
     * data is clearly invalid, return something like NULL that can easily
     * be deemed invalid in ::validateEntry(), however, can still produce a
     * useful error message indicating what is wrong with the input.
     */
    function parse($value) {
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * to_php
     *
     * Transforms the data from the value stored in the database to a PHP
     * value. The ::to_database() method is used to produce the database
     * valse, so this method is the compliment to ::to_database().
     *
     * Parameters:
     * $value - (string or null) database representation of the field's
     *      content
     */
    function to_php($value) {
        return $value;
    }

    /**
     * to_database
     *
     * Determines the value to be stored in the database. The database
     * backend for all fields is a text field, so this method should return
     * a text value or NULL to represent the value of the field. The
     * ::to_php() method will convert this value back to PHP.
     *
     * Paremeters:
     * $value - PHP value of the field's content
     */
    function to_database($value) {
        return $value;
    }

    /**
     * toString
     *
     * Converts the PHP value created in ::parse() or ::to_php() to a
     * pretty-printed value to show to the user. This is especially useful
     * for something like dates which are stored considerably different in
     * the database from their respective human-friendly versions.
     * Furthermore, this method allows for internationalization and
     * localization.
     *
     * Parametes:
     * $value - PHP value of the field's content
     */
    function toString($value) {
        return (string) $value;
    }

    function __toString() {
        return $this->toString($this->value);
    }

    /**
     * Returns an HTML friendly value for the data in the field.
     */
    function display($value) {
        return Format::htmlchars($this->toString($value));
    }

    /**
     * Returns a value suitable for exporting to a foreign system. Mostly
     * useful for things like dates and phone numbers which should be
     * formatted using a standard when exported
     */
    function export($value) {
        return $this->toString($value);
    }

    /**
     * Convert the field data to something matchable by filtering. The
     * primary use of this is for ticket filtering.
     */
    function getFilterData() {
        return $this->toString($this->getClean());
    }

    /**
     * Fetches a value that represents this content in a consistent,
     * searchable format. This is used by the search engine system and
     * backend.
     */
    function searchable($value) {
        return Format::searchable($this->toString($value));
    }

    /**
     * Fetches a list of options for searching. The values returned from
     * this method are passed to the widget's `::render()` method so that
     * the widget can be affected by this setting. For instance, date fields
     * might have a 'between' search option which should trigger rendering
     * of two date widgets for search results.
     */
    function getSearchMethods() {
        return array(
            'set' =>        __('has a value'),
            'notset' =>     __('does not have a value'),
            'equal' =>      __('is'),
            'equal.not' =>  __('is not'),
            'contains' =>   __('contains'),
            'match' =>      __('matches'),
        );
    }

    function getSearchMethodWidgets() {
        return array(
            'set' => null,
            'notset' => null,
            'equal' => array('TextboxField', array()),
            'equal.not' => array('TextboxField', array()),
            'contains' => array('TextboxField', array()),
            'match' => array('TextboxField', array(
                'placeholder' => __('Valid regular expression'),
                'configuration' => array('size'=>30),
                'validators' => function($self, $v) {
                    if (false === @preg_match($v, ' '))
                        $self->addError(__('Cannot compile this regular expression'));
                })),
        );
    }

    /**
     * This is used by the searching system to build a query for the search
     * engine. The function should return a criteria listing to match
     * content saved by the field by the `::to_database()` function.
     */
    function getSearchQ($method, $value, $name=false) {
        $criteria = array();
        $Q = new Q();
        $name = $name ?: $this->get('name');
        switch ($method) {
            case 'notset':
                $Q->negate();
            case 'set':
                $criteria[$name . '__isnull'] = false;
                break;

            case 'equal.not':
                $Q->negate();
            case 'equal':
                $criteria[$name . '__eq'] = $value;
                break;

            case 'contains':
                $criteria[$name . '__contains'] = $value;
                break;

            case 'match':
                $criteria[$name . '__regex'] = $value;
                break;
        }
        return $Q->add($criteria);
    }

    function getSearchWidget($method) {
        $methods = $this->getSearchMethodWidgets();
        $info = $methods[$method];
        if (is_array($info)) {
            $class = $info[0];
            return new $class($info[1]);
        }
        return $info;
    }

    function getLabel() { return $this->get('label'); }

    /**
     * getImpl
     *
     * Magic method that will return an implementation instance of this
     * field based on the simple text value of the 'type' value of this
     * field instance. The list of registered fields is determined by the
     * global get_dynamic_field_types() function. The data from this model
     * will be used to initialize the returned instance.
     *
     * For instance, if the value of this field is 'text', a TextField
     * instance will be returned.
     */
    function getImpl($parent=null) {
        // Allow registration with ::addFieldTypes and delayed calling
        $type = static::getFieldType($this->get('type'));
        $clazz = $type[1];
        $inst = new $clazz($this->ht);
        $inst->parent = $parent;
        $inst->setForm($this->_form);
        return $inst;
    }

    function __call($what, $args) {
        // XXX: Throw exception if $this->parent is not set
        if (!$this->parent)
            throw new Exception(sprintf(__('%s: Call to undefined function'),
                $what));
        // BEWARE: DynamicFormField has a __call() which will create a new
        //      FormField instance and invoke __call() on it or bounce
        //      immediately back
        return call_user_func_array(
            array($this->parent, $what), $args);
    }

    function getAnswer() { return $this->answer; }
    function setAnswer($ans) { $this->answer = $ans; }

    function getFormName() {
        if (is_numeric($this->get('id')))
            return substr(md5(
                session_id() . '-field-id-'.$this->get('id')), -16);
        else
            return $this->get('name') ?: $this->get('id');
    }

    function setForm($form) {
        $this->_form = $form;
    }
    function getForm() {
        return $this->_form;
    }
    /**
     * Returns the data source for this field. If created from a form, the
     * data source from the form is returned. Otherwise, if the request is a
     * POST, then _POST is returned.
     */
    function getSource() {
        if ($this->_form)
            return $this->_form->getSource();
        elseif ($_SERVER['REQUEST_METHOD'] == 'POST')
            return $_POST;
        else
            return array();
    }

    function render($options=array()) {
        $rv = $this->getWidget()->render($options);
        if ($v = $this->get('visibility')) {
            $v->emitJavascript($this);
        }
        return $rv;
    }

    function renderExtras($options=array()) {
        return;
    }

    function getMedia() {
        $widget = $this->getWidget();
        return $widget::$media;
    }

    function getConfigurationOptions() {
        return array();
    }

    /**
     * getConfiguration
     *
     * Loads configuration information from database into hashtable format.
     * Also, the defaults from ::getConfigurationOptions() are integrated
     * into the database-backed options, so that if options have not yet
     * been set or a new option has been added and not saved for this field,
     * the default value will be reflected in the returned configuration.
     */
    function getConfiguration() {
        if (!$this->_config) {
            $this->_config = $this->get('configuration');
            if (is_string($this->_config))
                $this->_config = JsonDataParser::parse($this->_config);
            elseif (!$this->_config)
                $this->_config = array();
            foreach ($this->getConfigurationOptions() as $name=>$field)
                if (!isset($this->_config[$name]))
                    $this->_config[$name] = $field->get('default');
        }
        return $this->_config;
    }

    /**
     * If the [Config] button should be shown to allow for the configuration
     * of this field
     */
    function isConfigurable() {
        return true;
    }

    /**
     * Field type is changeable in the admin interface
     */
    function isChangeable() {
        return true;
    }

    /**
     * Field does not contain data that should be saved to the database. Ie.
     * non data fields like section headers
     */
    function hasData() {
        return true;
    }

    /**
     * Returns true if the field/widget should be rendered as an entire
     * block in the target form.
     */
    function isBlockLevel() {
        return false;
    }

    /**
     * Fields should not be saved with the dynamic data. It is assumed that
     * some static processing will store the data elsewhere.
     */
    function isPresentationOnly() {
        return $this->presentation_only;
    }

    /**
     * Indicates if the field places data in the `value_id` column. This
     * is currently used by the materialized view system
     */
    function hasIdValue() {
        return false;
    }

    /**
     * Indicates if the field has subfields accessible via getSubFields()
     * method. Useful for filter integration. Should connect with
     * getFilterData()
     */
    function hasSubFields() {
        return false;
    }
    function getSubFields() {
        return null;
    }

    function getConfigurationForm($source=null) {
    	if (!$this->_cform) {
            $type = static::getFieldType($this->get('type'));
            $clazz = $type[1];
            $T = new $clazz($this->ht);
            $config = $this->getConfiguration();
            $this->_cform = new Form($T->getConfigurationOptions(), $source);
            if (!$source) {
                foreach ($this->_cform->getFields() as $name=>$f) {
                    if ($config && isset($config[$name]))
                        $f->value = $config[$name];
                    elseif ($f->get('default'))
                        $f->value = $f->get('default');
                }
            }
        }
        return $this->_cform;
    }

    function configure($prop, $value) {
        $this->getConfiguration();
        $this->_config[$prop] = $value;
    }

    function getWidget($widgetClass=false) {
        if (!static::$widget)
            throw new Exception(__('Widget not defined for this field'));
        if (!isset($this->_widget)) {
            $wc = $widgetClass ?: $this->get('widget') ?: static::$widget;
            $this->_widget = new $wc($this);
            $this->_widget->parseValue();
        }
        return $this->_widget;
    }

    function getSelectName() {
        $name = $this->get('name') ?: 'field_'.$this->get('id');
        if ($this->hasIdValue())
            $name .= '_id';

        return $name;
    }

    function getTranslateTag($subtag) {
        return _H(sprintf('field.%s.%s%s', $subtag, $this->get('id'),
            $this->get('form_id') ? '' : '*internal*'));
    }
    function getLocal($subtag, $default=false) {
        $tag = $this->getTranslateTag($subtag);
        $T = CustomDataTranslation::translate($tag);
        return $T != $tag ? $T : ($default ?: $this->get($subtag));
    }
}

class TextboxField extends FormField {
    static $widget = 'TextboxWidget';

    function getConfigurationOptions() {
        return array(
            'size'  =>  new TextboxField(array(
                'id'=>1, 'label'=>__('Size'), 'required'=>false, 'default'=>16,
                    'validator' => 'number')),
            'length' => new TextboxField(array(
                'id'=>2, 'label'=>__('Max Length'), 'required'=>false, 'default'=>30,
                    'validator' => 'number')),
            'validator' => new ChoiceField(array(
                'id'=>3, 'label'=>__('Validator'), 'required'=>false, 'default'=>'',
                'choices' => array('phone'=>__('Phone Number'),'email'=>__('Email Address'),
                    'ip'=>__('IP Address'), 'number'=>__('Number'),
                    'regex'=>__('Custom (Regular Expression)'), ''=>__('None')))),
            'regex' => new TextboxField(array(
                'id'=>6, 'label'=>__('Regular Expression'), 'required'=>true,
                'configuration'=>array('size'=>40, 'length'=>100),
                'visibility' => new VisibilityConstraint(
                    new Q(array('validator__eq'=>'regex')),
                    VisibilityConstraint::HIDDEN
                ),
                'cleaners' => function ($self, $value) {
                    $wrapped = "/".$value."/iu";
                    if (false === @preg_match($value, ' ')
                            && false !== @preg_match($wrapped, ' ')) {
                        $value = $wrapped;
                    }
                    if ($value == '//iu')
                        return '';

                    return $value;
                },
                'validators' => function($self, $v) {
                    if (false === @preg_match($v, ' '))
                        $self->addError(__('Cannot compile this regular expression'));
                })),
            'validator-error' => new TextboxField(array(
                'id'=>4, 'label'=>__('Validation Error'), 'default'=>'',
                'configuration'=>array('size'=>40, 'length'=>60,
                    'translatable'=>$this->getTranslateTag('validator-error')
                ),
                'hint'=>__('Message shown to user if the input does not match the validator'))),
            'placeholder' => new TextboxField(array(
                'id'=>5, 'label'=>__('Placeholder'), 'required'=>false, 'default'=>'',
                'hint'=>__('Text shown in before any input from the user'),
                'configuration'=>array('size'=>40, 'length'=>40,
                    'translatable'=>$this->getTranslateTag('placeholder')
                ),
            )),
        );
    }

    function validateEntry($value) {
        parent::validateEntry($value);
        $config = $this->getConfiguration();
        $validators = array(
            '' =>       null,
            'email' =>  array(array('Validator', 'is_email'),
                __('Enter a valid email address')),
            'phone' =>  array(array('Validator', 'is_phone'),
                __('Enter a valid phone number')),
            'ip' =>     array(array('Validator', 'is_ip'),
                __('Enter a valid IP address')),
            'number' => array('is_numeric', __('Enter a number')),
            'regex' => array(
                function($v) use ($config) {
                    $regex = $config['regex'];
                    return @preg_match($regex, $v);
                }, __('Value does not match required pattern')
            ),
        );
        // Support configuration forms, as well as GUI-based form fields
        $valid = $this->get('validator');
        if (!$valid) {
            $valid = $config['validator'];
        }
        if (!$value || !isset($validators[$valid]))
            return;
        $func = $validators[$valid];
        $error = $func[1];
        if ($config['validator-error'])
            $error = $this->getLocal('validator-error', $config['validator-error']);
        if (is_array($func) && is_callable($func[0]))
            if (!call_user_func($func[0], $value))
                $this->_errors[] = $error;
    }
}

class PasswordField extends TextboxField {
    static $widget = 'PasswordWidget';

    function to_database($value) {
        return Crypto::encrypt($value, SECRET_SALT, $this->getFormName());
    }

    function to_php($value) {
        return Crypto::decrypt($value, SECRET_SALT, $this->getFormName());
    }
}

class TextareaField extends FormField {
    static $widget = 'TextareaWidget';

    function getConfigurationOptions() {
        return array(
            'cols'  =>  new TextboxField(array(
                'id'=>1, 'label'=>__('Width').' '.__('(chars)'), 'required'=>true, 'default'=>40)),
            'rows'  =>  new TextboxField(array(
                'id'=>2, 'label'=>__('Height').' '.__('(rows)'), 'required'=>false, 'default'=>4)),
            'length' => new TextboxField(array(
                'id'=>3, 'label'=>__('Max Length'), 'required'=>false, 'default'=>0)),
            'html' => new BooleanField(array(
                'id'=>4, 'label'=>__('HTML'), 'required'=>false, 'default'=>true,
                'configuration'=>array('desc'=>__('Allow HTML input in this box')))),
            'placeholder' => new TextboxField(array(
                'id'=>5, 'label'=>__('Placeholder'), 'required'=>false, 'default'=>'',
                'hint'=>__('Text shown in before any input from the user'),
                'configuration'=>array('size'=>40, 'length'=>40,
                'translatable'=>$this->getTranslateTag('placeholder')),
            	)),
        );
    }

    function display($value) {
        $config = $this->getConfiguration();
        if ($config['html'])
            return Format::safe_html($value);
        else
            return nl2br(Format::htmlchars($value));
    }

    function searchable($value) {
        $value = preg_replace(array('`<br(\s*)?/?>`i', '`</div>`i'), "\n", $value);
        $value = Format::htmldecode(Format::striptags($value));
        return Format::searchable($value);
    }

    function export($value) {
        return (!$value) ? $value : Format::html2text($value);
    }

    function parse($value) {
        $config = $this->getConfiguration();
        if ($config['html'])
            return Format::sanitize($value);
        else
            return $value;
    }

	function isTranslatable() {
		return true;
	}
}

class PhoneField extends FormField {
    static $widget = 'PhoneNumberWidget';

    function getConfigurationOptions() {
        return array(
            'ext' => new BooleanField(array(
                'label'=>__('Extension'), 'default'=>true,
                'configuration'=>array(
                    'desc'=>__('Add a separate field for the extension'),
                ),
            )),
            'digits' => new TextboxField(array(
                'label'=>__('Minimum length'), 'default'=>7,
                'hint'=>__('Fewest digits allowed in a valid phone number'),
                'configuration'=>array('validator'=>'number', 'size'=>5),
            )),
            'format' => new ChoiceField(array(
                'label'=>__('Display format'), 'default'=>'us',
                'choices'=>array(''=>'-- '.__('Unformatted').' --',
                    'us'=>__('United States')),
            )),
        );
    }

    function validateEntry($value) {
        parent::validateEntry($value);
        $config = $this->getConfiguration();
        # Run validator against $this->value for email type
        list($phone, $ext) = explode("X", $value, 2);
        if ($phone && (
                !is_numeric($phone) ||
                strlen($phone) < $config['digits']))
            $this->_errors[] = __("Enter a valid phone number");
        if ($ext && $config['ext']) {
            if (!is_numeric($ext))
                $this->_errors[] = __("Enter a valid phone extension");
            elseif (!$phone)
                $this->_errors[] = __("Enter a phone number for the extension");
        }
    }

    function parse($value) {
        // NOTE: Value may have a legitimate 'X' to separate the number and
        // extension parts. Don't remove the 'X'
        $val = preg_replace('/[^\dX]/', '', $value);
        // Pass completely-incorrect string for validation error
        return $val ?: $value;
    }

    function toString($value) {
        $config = $this->getConfiguration();
        list($phone, $ext) = explode("X", $value, 2);
        switch ($config['format']) {
        case 'us':
            $phone = Format::phone($phone);
            break;
        }
        if ($ext)
            $phone.=" x$ext";
        return $phone;
    }
}

class BooleanField extends FormField {
    static $widget = 'CheckboxWidget';

    function getConfigurationOptions() {
    	//die("Setting translatable to ".$this->getTranslateTag('desc'));
    	return array(
            'desc' => new TextareaField(array(
                'id'=>1, 'label'=>__('Description'), 'required'=>false, 'default'=>'',
                'hint'=>__('Text shown inline with the widget'),
                'configuration'=>array(
                		'rows'=>2,
           				'translatable'=>$this->getTranslateTag('desc')
    					),
                 )),
        		
            'default' => new BooleanField(array(
                'id'=>2, 'label'=>__('Default'), 'required'=>false, 'default'=>true,
                //'hint'=>__('Shall the checkbox be initially set?'),
                'configuration'=>array(
                    'desc'=>'Shall the checkbox be initially set?')
            	)),
        	);
    }

    function to_database($value) {
    	return ($value) ? '1' : '0';
    }

    function parse($value) {
    	return $this->to_php($value);
    }
    function to_php($value) {
    	return $value ? true : false;
    }

    function toString($value) {
    	return ($value) ? __('Yes') : __('No');
    }

    function getSearchMethods() {
        return array(
            'set' =>        __('checked'),
            'set.not' =>    __('unchecked'),
        );
    }

    function getSearchMethodWidgets() {
        return array(
            'set' => null,
            'set.not' => null,
        );
    }
}

class ChoiceField extends FormField {
    static $widget = 'ChoicesWidget';

    function getConfigurationOptions() {
        return array(
            'choices'  =>  new TextareaField(array(
                'id'=>1, 'label'=>__('Choices'), 'required'=>false, 'default'=>'',
                'hint'=>__('List choices, one per line. To protect against spelling changes, specify key:value names to preserve entries if the list item names change'),
                'configuration'=>array('html'=>false)
            )),
            'default' => new TextboxField(array(
                'id'=>3, 'label'=>__('Default'), 'required'=>false, 'default'=>'',
                'hint'=>__('(Enter a key). Value selected from the list initially'),
                'configuration'=>array('size'=>20, 'length'=>40),
            )),
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40,
                    'translatable'=>$this->getTranslateTag('prompt'),
                ),
            )),
            'multiselect' => new BooleanField(array(
                'id'=>1, 'label'=>'Multiselect', 'required'=>false, 'default'=>false,
                'configuration'=>array(
                    'desc'=>'Allow multiple selections')
            )),
        );
    }

    function parse($value) {
        return $this->to_php($value ?: null);
    }

    function to_database($value) {
        if (!is_array($value)) {
            $choices = $this->getChoices();
            if (isset($choices[$value]))
                $value = array($value => $choices[$value]);
        }
        if (is_array($value))
            $value = JsonDataEncoder::encode($value);

        return $value;
    }

    function to_php($value) {
        if (is_string($value))
            $value = JsonDataParser::parse($value) ?: $value;

        // CDATA table may be built with comma-separated key,value,key,value
        if (is_string($value)) {
            $values = array();
            $choices = $this->getChoices();
            foreach (explode(',', $value) as $V) {
                if (isset($choices[$V]))
                    $values[$V] = $choices[$V];
            }
            if (array_filter($values))
                $value = $values;
        }
        $config = $this->getConfiguration();
        if (!$config['multiselect'] && is_array($value) && count($value) < 2) {
            reset($value);
            return key($value);
        }
        return $value;
    }

    function toString($value) {
        if (!is_array($value))
            $value = $this->getChoice($value);
        if (is_array($value))
            return implode(', ', $value);
        return (string) $value;
    }

    function getChoice($value) {
        $choices = $this->getChoices();
        $selection = array();
        if ($value && is_array($value)) {
            $selection = $value;
        } elseif (isset($choices[$value]))
            $selection[] = $choices[$value];
        elseif ($this->get('default'))
            $selection[] = $choices[$this->get('default')];

        return $selection;
    }

    function getChoices($verbose=false) {
        if ($this->_choices === null || $verbose) {
            // Allow choices to be set in this->ht (for configurationOptions)
            $this->_choices = $this->get('choices');
            if (!$this->_choices) {
                $this->_choices = array();
                $config = $this->getConfiguration();
                $choices = explode("\n", $config['choices']);
                foreach ($choices as $choice) {
                    // Allow choices to be key: value
                    list($key, $val) = explode(':', $choice);
                    if ($val == null)
                        $val = $key;
                    $this->_choices[trim($key)] = trim($val);
                }
                // Add old selections if nolonger available
                // This is necessary so choices made previously can be
                // retained
                $values = ($a=$this->getAnswer()) ? $a->getValue() : array();
                if ($values && is_array($values)) {
                    foreach ($values as $k => $v) {
                        if (!isset($this->_choices[$k])) {
                            if ($verbose) $v .= ' (retired)';
                            $this->_choices[$k] = $v;
                        }
                    }
                }
            }
        }
        return $this->_choices;
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
            return Q::not(array("{$name}__in" => array_keys($value)));
        case 'includes':
            return new Q(array("{$name}__in" => array_keys($value)));
        default:
            return parent::getSearchQ($method, $value, $name);
        }
    }
}

class DatetimeField extends FormField {
    static $widget = 'DatetimePickerWidget';

    function to_database($value) {
        // Store time in gmt time, unix epoch format
        return (string) $value;
    }

    function to_php($value) {
        if (!$value)
            return $value;
        else
            return (int) $value;
    }

    function toString($value) {
        global $cfg;
        $config = $this->getConfiguration();
        // If GMT is set, convert to local time zone. Otherwise, leave
        // unchanged (default TZ is UTC)
        if ($config['time'])
            return Format::datetime($value, false, !$config['gmt'] ? 'UTC' : false);
        else
            return Format::date($value, false, false, !$config['gmt'] ? 'UTC' : false);
    }

    function export($value) {
        $config = $this->getConfiguration();
        if (!$value)
            return '';
        else
            return Format::date($value, false, 'y-MM-dd HH:mm:ss', !$config['gmt'] ? 'UTC' : false);
    }

    function getConfigurationOptions() {
        return array(
            'time' => new BooleanField(array(
                'id'=>1, 'label'=>__('Time'), 'required'=>false, 'default'=>false,
                'configuration'=>array(
                    'desc'=>__('Show time selection with date picker')))),
            'gmt' => new BooleanField(array(
                'id'=>2, 'label'=>__('Timezone Aware'), 'required'=>false,
                'configuration'=>array(
                    'desc'=>__("Show date/time relative to user's timezone")))),
            'min' => new DatetimeField(array(
                'id'=>3, 'label'=>__('Earliest'), 'required'=>false,
                'hint'=>__('Earliest date selectable'))),
            'max' => new DatetimeField(array(
                'id'=>4, 'label'=>__('Latest'), 'required'=>false,
                'default'=>null, 'hint'=>__('Latest date selectable'))),
            'future' => new BooleanField(array(
                'id'=>5, 'label'=>__('Allow Future Dates'), 'required'=>false,
                'default'=>true, 'configuration'=>array(
                    'desc'=>__('Allow entries into the future' /* Used in the date field */)),
            )),
        );
    }

    function validateEntry($value) {
        $config = $this->getConfiguration();
        parent::validateEntry($value);
        if (!$value) return;
        if ($config['min'] and $value < $config['min'])
            $this->_errors[] = __('Selected date is earlier than permitted');
        elseif ($config['max'] and $value > $config['max'])
            $this->_errors[] = __('Selected date is later than permitted');
        // strtotime returns -1 on error for PHP < 5.1.0 and false thereafter
        elseif ($value === -1 or $value === false)
            $this->_errors[] = __('Enter a valid date');
    }

    function getSearchMethods() {
        return array(
            'set' =>        __('has a value'),
            'notset' =>     __('does not have a value'),
            'equal' =>      __('on'),
            'notequal' =>   __('not on'),
            'before' =>     __('before'),
            'after' =>      __('after'),
            'between' =>    __('between'),
            'ndaysago' =>   __('in the last n days'),
            'ndays' =>      __('in the next n days'),
        );
    }

    function getSearchMethodWidgets() {
        $config_notime = $config = $this->getConfiguration();
        $config_notime['time'] = false;
        return array(
            'set' => null,
            'notset' => null,
            'equal' => array('DatetimeField', array(
                'configuration' => $config_notime,
            )),
            'notequal' => array('DatetimeField', array(
                'configuration' => $config_notime,
            )),
            'before' => array('DatetimeField', array(
                'configuration' => $config,
            )),
            'after' => array('DatetimeField', array(
                'configuration' => $config,
            )),
            'between' => array('InlineformField', array(
                'form' => array(
                    'left' => new DatetimeField(),
                    'text' => new FreeTextField(array(
                        'configuration' => array('content' => 'and'))
                    ),
                    'right' => new DatetimeField(),
                ),
            )),
            'ndaysago' => array('InlineformField', array(
                'form' => array(
                    'until' => new TextboxField(array(
                        'configuration' => array('validator'=>'number', 'size'=>4))
                    ),
                    'text' => new FreeTextField(array(
                        'configuration' => array('content' => 'days'))
                    ),
                ),
            )),
            'ndays' => array('InlineformField', array(
                'form' => array(
                    'until' => new TextboxField(array(
                        'configuration' => array('validator'=>'number', 'size'=>4))
                    ),
                    'text' => new FreeTextField(array(
                        'configuration' => array('content' => 'days'))
                    ),
                ),
            )),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        $name = $name ?: $this->get('name');
        switch ($method) {
        case 'after':
            return new Q(array("{$name}__gte" => $value));
        case 'before':
            return new Q(array("{$name}__lt" => $value));
        case 'between':
            return new Q(array(
                "{$name}__gte" => $value['left'],
                "{$name}__lte" => $value['right'],
            ));
        case 'ndaysago':
            return new Q(array(
                "{$name}__lt" => SqlFunction::NOW(),
                "{$name}__gte" => SqlExpression::minus(SqlFunction::NOW(), SqlInterval::DAY($value['until'])),
            ));
        case 'ndays':
            return new Q(array(
                "{$name}__gt" => SqlFunction::NOW(),
                "{$name}__lte" => SqlExpression::plus(SqlFunction::NOW(), SqlInterval::DAY($value['until'])),
            ));
        default:
            return parent::getSearchQ($method, $value, $name);
        }
    }
}

/**
 * This is kind-of a special field that doesn't have any data. It's used as
 * a field to provide a horizontal section break in the display of a form
 */
class SectionBreakField extends FormField {
    static $widget = 'SectionBreakWidget';

    function hasData() {
        return false;
    }

    function isBlockLevel() {
        return true;
    }
}

class ThreadEntryField extends FormField {
    static $widget = 'ThreadEntryWidget';

    function isChangeable() {
        return false;
    }
    function isBlockLevel() {
        return true;
    }
    function isPresentationOnly() {
        return true;
    }

    function getConfigurationOptions() {
        global $cfg;

        $attachments = new FileUploadField();
        $fileupload_config = $attachments->getConfigurationOptions();
        if ($cfg->getAllowedFileTypes())
            $fileupload_config['extensions']->set('default', $cfg->getAllowedFileTypes());

        foreach ($fileupload_config as $C) {
            $C->set('visibility', new VisibilityConstraint(new Q(array(
                'attachments__eq'=>true,
            )), VisibilityConstraint::HIDDEN));
        }
        return array(
            'attachments' => new BooleanField(array(
                'label'=>__('Enable Attachments'),
                'default'=>$cfg->allowAttachments(),
                'configuration'=>array(
                    'desc'=>__('Enables attachments, regardless of channel'),
                ),
                'validators' => function($self, $value) {
                    if (!ini_get('file_uploads'))
                        $self->addError(__('The "file_uploads" directive is disabled in php.ini'));
                }
            )),
        )
        + $fileupload_config;
    }

    function isAttachmentsEnabled() {
        $config = $this->getConfiguration();
        return $config['attachments'];
    }
}

class PriorityField extends ChoiceField {
    function getWidget() {
        $widget = parent::getWidget();
        if ($widget->value instanceof Priority)
            $widget->value = $widget->value->getId();
        return $widget;
    }

    function hasIdValue() {
        return true;
    }

    function getChoices() {
        global $cfg;
        $this->ht['default'] = $cfg->getDefaultPriorityId();

        $sql = 'SELECT priority_id, priority_desc FROM '.PRIORITY_TABLE
              .' ORDER BY priority_urgency DESC';
        $choices = array();
        if (!($res = db_query($sql)))
            return $choices;

        while ($row = db_fetch_row($res))
            $choices[$row[0]] = $row[1];
        return $choices;
    }

    function parse($id) {
        return $this->to_php(null, $id);
    }

    function to_php($value, $id=false) {
        if (is_array($id)) {
            reset($id);
            $id = key($id);
        }
        return Priority::lookup($id);
    }

    function to_database($prio) {
        return ($prio instanceof Priority)
            ? array($prio->getDesc(), $prio->getId())
            : $prio;
    }

    function toString($value) {
        return ($value instanceof Priority) ? $value->getDesc() : $value;
    }

    function searchable($value) {
        // Priority isn't searchable this way
        return null;
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }
}
FormField::addFieldTypes(/*@trans*/ 'Dynamic Fields', function() {
    return array(
        'priority' => array(__('Priority Level'), PriorityField),
    );
});


class DepartmentField extends ChoiceField {
    function getWidget() {
        $widget = parent::getWidget();
        if ($widget->value instanceof Dept)
            $widget->value = $widget->value->getId();
        return $widget;
    }

    function hasIdValue() {
        return true;
    }

    function getChoices() {
        global $cfg;

        $choices = array();
        if (($depts = Dept::getDepartments()))
            foreach ($depts as $id => $name)
                $choices[$id] = $name;

        return $choices;
    }

    function parse($id) {
        return $this->to_php(null, $id);
    }

    function to_php($value, $id=false) {
        if (is_array($id)) {
            reset($id);
            $id = key($id);
        }
        return $id;
    }

    function to_database($dept) {
        return ($dept instanceof Dept)
            ? array($dept->getName(), $dept->getId())
            : $dept;
    }

    function toString($value) {
        return (string) $value;
    }

    function searchable($value) {
        return null;
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }
}
FormField::addFieldTypes(/*@trans*/ 'Dynamic Fields', function() {
    return array(
        'department' => array(__('Department'), DepartmentField),
    );
});


class AssigneeField extends ChoiceField {
    function getWidget() {
        $widget = parent::getWidget();
        if (is_object($widget->value))
            $widget->value = $widget->value->getId();
        return $widget;
    }

    function hasIdValue() {
        return true;
    }

    function getChoices() {
        global $cfg;
        $choices = array();
        if (($agents = Staff::getAvailableStaffMembers()))
            foreach ($agents as $id => $name)
                $choices[$id] = $name;

        return $choices;
    }

    function parse($id) {
        return $this->to_php(null, $id);
    }

    function to_php($value, $id=false) {
        if (is_array($id)) {
            reset($id);
            $id = key($id);
        }

        return $id;
    }


    function to_database($value) {
        return (is_object($value))
            ? array($value->getName(), $value->getId())
            : $value;
    }

    function toString($value) {
        return (string) $value;
    }

    function searchable($value) {
        return null;
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }
}
FormField::addFieldTypes(/*@trans*/ 'Dynamic Fields', function() {
    return array(
        'assignee' => array(__('Assignee'), AssigneeField),
    );
});


class TicketStateField extends ChoiceField {

    static $_states = array(
            'open' => array(
                'name' => /* @trans, @context "ticket state name" */ 'Open',
                'verb' => /* @trans, @context "ticket state action" */ 'Open'
                ),
            'closed' => array(
                'name' => /* @trans, @context "ticket state name" */ 'Closed',
                'verb' => /* @trans, @context "ticket state action" */ 'Close'
                )
            );
    // Private states
    static $_privatestates = array(
            'archived' => array(
                'name' => /* @trans, @context "ticket state name" */ 'Archived',
                'verb' => /* @trans, @context "ticket state action" */ 'Archive'
                ),
            'deleted'  => array(
                'name' => /* @trans, @context "ticket state name" */ 'Deleted',
                'verb' => /* @trans, @context "ticket state action" */ 'Delete'
                )
            );

    function hasIdValue() {
        return true;
    }

    function isChangeable() {
        return false;
    }

    function getChoices() {
        static $_choices;

        if (!isset($_choices)) {
            // Translate and cache the choices
            foreach (static::$_states as $k => $v)
                $_choices[$k] =  _P('ticket state name', $v['name']);

            $this->ht['default'] =  '';
        }

        return $_choices;
    }

    function getChoice($state) {

        if ($state && is_array($state))
            $state = key($state);

        if (isset(static::$_states[$state]))
            return _P('ticket state name', static::$_states[$state]['name']);

        if (isset(static::$_privatestates[$state]))
            return _P('ticket state name', static::$_privatestates[$state]['name']);

        return $state;
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=> __('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=> __('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }

    static function getVerb($state) {

        if (isset(static::$_states[$state]))
            return _P('ticket state action', static::$_states[$state]['verb']);

        if (isset(static::$_privatestates[$state]))
            return _P('ticket state action', static::$_privatestates[$state]['verb']);
    }
}
FormField::addFieldTypes('Dynamic Fields', function() {
    return array(
        'state' => array('Ticket State', TicketStateField, false),
    );
});

class TicketFlagField extends ChoiceField {

    // Supported flags (TODO: move to configurable custom list)
    static $_flags = array(
            'onhold' => array(
                'flag' => 1,
                'name' => 'Onhold',
                'states' => array('open'),
                ),
            'overdue' => array(
                'flag' => 2,
                'name' => 'Overdue',
                'states' => array('open'),
                ),
            'answered' => array(
                'flag' => 4,
                'name' => 'Answered',
                'states' => array('open'),
                )
            );

    var $_choices;

    function hasIdValue() {
        return true;
    }

    function isChangeable() {
        return true;
    }

    function getChoices() {
        $this->ht['default'] =  '';

        if (!$this->_choices) {
            foreach (static::$_flags as $k => $v)
                $this->_choices[$k] = $v['name'];
        }

        return $this->_choices;
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>'Prompt', 'required'=>false, 'default'=>'',
                'hint'=>'Leading text shown before a value is selected',
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }
}

FormField::addFieldTypes('Dynamic Fields', function() {
    return array(
        'flags' => array('Ticket Flags', TicketFlagField, false),
    );
});

class FileUploadField extends FormField {
    static $widget = 'FileUploadWidget';

    protected $attachments;

    static function getFileTypes() {
        static $filetypes;

        if (!isset($filetypes))
            $filetypes = YamlDataParser::load(INCLUDE_DIR . '/config/filetype.yaml');
        return $filetypes;
    }

    function getConfigurationOptions() {
        // Compute size selections
        $sizes = array('262144' => '— '.__('Small').' —');
        $next = 512 << 10;
        $max = strtoupper(ini_get('upload_max_filesize'));
        $limit = (int) $max;
        if (!$limit) $limit = 2 << 20; # 2M default value
        elseif (strpos($max, 'K')) $limit <<= 10;
        elseif (strpos($max, 'M')) $limit <<= 20;
        elseif (strpos($max, 'G')) $limit <<= 30;
        while ($next <= $limit) {
            // Select the closest, larger value (in case the
            // current value is between two)
            $sizes[$next] = Format::file_size($next);
            $next *= 2;
        }
        // Add extra option if top-limit in php.ini doesn't fall
        // at a power of two
        if ($next < $limit * 2)
            $sizes[$limit] = Format::file_size($limit);

        $types = array();
        foreach (self::getFileTypes() as $type=>$info) {
            $types[$type] = $info['description'];
        }

        global $cfg;
        return array(
            'size' => new ChoiceField(array(
                'label'=>__('Maximum File Size'),
                'hint'=>__('Choose maximum size of a single file uploaded to this field'),
                'default'=>$cfg->getMaxFileSize(),
                'choices'=>$sizes
            )),
            'mimetypes' => new ChoiceField(array(
                'label'=>__('Restrict by File Type'),
                'hint'=>__('Optionally, choose acceptable file types.'),
                'required'=>false,
                'choices'=>$types,
                'configuration'=>array('multiselect'=>true,'prompt'=>__('No restrictions'))
            )),
            'extensions' => new TextareaField(array(
                'label'=>__('Additional File Type Filters'),
                'hint'=>__('Optionally, enter comma-separated list of additional file types, by extension. (e.g .doc, .pdf).'),
                'configuration'=>array('html'=>false, 'rows'=>2),
            )),
            'max' => new TextboxField(array(
                'label'=>__('Maximum Files'),
                'hint'=>__('Users cannot upload more than this many files.'),
                'default'=>false,
                'required'=>false,
                'validator'=>'number',
                'configuration'=>array('size'=>8, 'length'=>4, 'placeholder'=>__('No limit')),
            ))
        );
    }

    /**
     * Called from the ajax handler for async uploads via web clients.
     */
    function ajaxUpload($bypass=false) {
        $config = $this->getConfiguration();

        $files = AttachmentFile::format($_FILES['upload'],
            // For numeric fields assume configuration exists
            !is_numeric($this->get('id')));
        if (count($files) != 1)
            Http::response(400, 'Send one file at a time');
        $file = array_shift($files);
        $file['name'] = urldecode($file['name']);

        if (!$bypass && !$this->isValidFileType($file['name'], $file['type']))
            Http::response(415, 'File type is not allowed');

        $config = $this->getConfiguration();
        if (!$bypass && $file['size'] > $config['size'])
            Http::response(413, 'File is too large');

        if (!($F = AttachmentFile::upload($file)))
            Http::response(500, 'Unable to store file: '. $file['error']);

        return $F->getId();
    }

    /**
     * Called from FileUploadWidget::getValue() when manual upload is used
     * for browsers which do not support the HTML5 way of uploading async.
     */
    function uploadFile($file) {
        if (!$this->isValidFileType($file['name'], $file['type']))
            throw new FileUploadError(__('File type is not allowed'));

        $config = $this->getConfiguration();
        if ($file['size'] > $config['size'])
            throw new FileUploadError(__('File size is too large'));

        return AttachmentFile::upload($file);
    }

    /**
     * Called from API and email routines and such to handle attachments
     * sent other than via web upload
     */
    function uploadAttachment(&$file) {
        if (!$this->isValidFileType($file['name'], $file['type']))
            throw new FileUploadError(__('File type is not allowed'));

        if (is_callable($file['data']))
            $file['data'] = $file['data']();
        if (!isset($file['size'])) {
            // bootstrap.php include a compat version of mb_strlen
            if (extension_loaded('mbstring'))
                $file['size'] = mb_strlen($file['data'], '8bit');
            else
                $file['size'] = strlen($file['data']);
        }

        $config = $this->getConfiguration();
        if ($file['size'] > $config['size'])
            throw new FileUploadError(__('File size is too large'));

        if (!$F = AttachmentFile::create($file))
            throw new FileUploadError(__('Unable to save file'));

        return $F;
    }

    function isValidFileType($name, $type=false) {
        $config = $this->getConfiguration();

        // Check MIME type - file ext. shouldn't be solely trusted.
        if ($type && $config['__mimetypes']
                && in_array($type, $config['__mimetypes']))
            return true;

        // Return true if all file types are allowed (.*)
        if (!$config['__extensions'] || in_array('.*', $config['__extensions']))
            return true;

        $allowed = $config['__extensions'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return ($ext && is_array($allowed) && in_array(".$ext", $allowed));
    }

    function getFiles() {
        if (!isset($this->attachments) && ($a = $this->getAnswer())
            && ($e = $a->getEntry()) && ($e->get('id'))
        ) {
            $this->attachments = new GenericAttachments(
                // Combine the field and entry ids to make the key
                sprintf('%u', crc32('E'.$this->get('id').$e->get('id'))),
                'E');
        }
        return $this->attachments ? $this->attachments->getAll() : array();
    }

    function getConfiguration() {
        $config = parent::getConfiguration();
        $_types = self::getFileTypes();
        $mimetypes = array();
        $extensions = array();
        if (isset($config['mimetypes']) && is_array($config['mimetypes'])) {
            foreach ($config['mimetypes'] as $type=>$desc) {
                foreach ($_types[$type]['types'] as $mime=>$exts) {
                    $mimetypes[$mime] = true;
                    if (is_array($exts))
                        foreach ($exts as $ext)
                            $extensions['.'.$ext] = true;
                }
            }
        }
        if (strpos($config['extensions'], '.*') !== false)
            $config['extensions'] = '';

        if (is_string($config['extensions'])) {
            foreach (preg_split('/\s+/', str_replace(',',' ', $config['extensions'])) as $ext) {
                if (!$ext) {
                    continue;
                }
                elseif (strpos($ext, '/')) {
                    $mimetypes[$ext] = true;
                }
                else {
                    if ($ext[0] != '.')
                        $ext = '.' . $ext;

                    // Ensure that the extension is lower-cased for comparison latr
                    $ext = strtolower($ext);

                    // Add this to the MIME types list so it can be exported to
                    // the @accept attribute
                    if (!isset($extensions[$ext]))
                        $mimetypes[$ext] = true;

                    $extensions[$ext] = true;
                }
            }
            $config['__extensions'] = array_keys($extensions);
        }
        elseif (is_array($config['extensions'])) {
            $config['__extensions'] = $config['extensions'];
        }

        // 'mimetypes' is the array represented from the user interface,
        // '__mimetypes' is a complete list of supported MIME types.
        $config['__mimetypes'] = array_keys($mimetypes);
        return $config;
    }

    // When the field is saved to database, encode the ID listing as a json
    // array. Then, inspect the difference between the files actually
    // attached to this field
    function to_database($value) {
        $this->getFiles();
        if (isset($this->attachments)) {
            $ids = array();
            // Handle deletes
            foreach ($this->attachments->getAll() as $f) {
                if (!in_array($f['id'], $value))
                    $this->attachments->delete($f['id']);
                else
                    $ids[] = $f['id'];
            }
            // Handle new files
            foreach ($value as $id) {
                if (!in_array($id, $ids))
                    $this->attachments->upload($id);
            }
        }
        return JsonDataEncoder::encode($value);
    }

    function parse($value) {
        // Values in the database should be integer file-ids
        return array_map(function($e) { return (int) $e; },
            $value ?: array());
    }

    function to_php($value) {
        return JsonDataParser::decode($value);
    }

    function display($value) {
        $links = array();
        foreach ($this->getFiles() as $f) {
            $links[] = sprintf('<a class="no-pjax" href="%s">%s</a>',
                Format::htmlchars($f['download_url']), Format::htmlchars($f['name']));
        }
        return implode('<br/>', $links);
    }

    function toString($value) {
        $files = array();
        foreach ($this->getFiles() as $f) {
            $files[] = $f['name'];
        }
        return implode(', ', $files);
    }
}

class InlineFormData extends ArrayObject {
    var $_form;

    function __construct($form, array $data=array()) {
        parent::__construct($data);
        $this->_form = $form;
    }

    function getVar($tag) {
        foreach ($this->_form->getFields() as $f) {
            if ($f->get('name') == $tag)
                return $this[$f->get('id')];
        }
    }
}


class InlineFormField extends FormField {
    static $widget = 'InlineFormWidget';

    var $_iform = null;

    function validateEntry($value) {
        if (!$this->getInlineForm()->isValid()) {
            $this->_errors[] = __('Correct errors in the inline form');
        }
    }

    function parse($value) {
        // The InlineFieldWidget returns an array of cleaned data
        return $value;
    }

    function to_database($value) {
        return JsonDataEncoder::encode($value);
    }

    function to_php($value) {
        $data = JsonDataParser::decode($value);
        // The InlineFormData helps with the variable replacer API
        return new InlineFormData($this->getInlineForm(), $data);
    }

    function display($data) {
        $form = $this->getInlineForm();
        ob_start(); ?>
        <div><?php
        foreach ($form->getFields() as $field) { ?>
            <span style="display:inline-block;padding:0 5px;vertical-align:top">
                <strong><?php echo Format::htmlchars($field->get('label')); ?></strong>
                <div><?php
                    $value = $data[$field->get('id')];
                    echo $field->display($value); ?></div>
            </span><?php
        } ?>
        </div><?php
        return ob_get_clean();
    }

    function getInlineForm($data=false) {
        $form = $this->get('form');
        if (is_array($form)) {
            $form = new Form($form, $data ?: $this->value ?: $this->getSource());
        }
        return $form;
    }
}

class InlineDynamicFormField extends FormField {
    function getInlineForm($data=false) {
        if (!isset($this->_iform) || $data) {
            $config = $this->getConfiguration();
            $this->_iform = DynamicForm::lookup($config['form']);
            if ($data)
                $this->_iform = $this->_iform->getForm($data);
        }
        return $this->_iform;
    }

    function getConfigurationOptions() {
        $forms = DynamicForm::objects()->filter(array('type'=>'G'))
            ->values_flat('id', 'title');
        $choices = array();
        foreach ($forms as $row) {
            list($id, $title) = $row;
            $choices[$id] = $title;
        }
        return array(
            'form' => new ChoiceField(array(
                'id'=>2, 'label'=>'Inline Form', 'required'=>true,
                'default'=>'', 'choices'=>$choices
            )),
        );
    }
}

class InlineFormWidget extends Widget {
    function render($mode=false) {
        $form = $this->field->getInlineForm();
        if (!$form)
            return;
        // Handle first-step edits -- load data from $this->value
        if ($form instanceof DynamicForm && !$form->getSource())
            $form = $form->getForm($this->value);
        $inc = ($mode == 'client') ? CLIENTINC_DIR : STAFFINC_DIR;
        include $inc . 'templates/inline-form.tmpl.php';
    }

    function getValue() {
        $data = $this->field->getSource();
        if (!$data)
            return null;
        $form = $this->field->getInlineForm($data);
        if (!$form)
            return null;
        return $form->getClean();
    }
}

class Widget {
    static $media = null;

    function __construct($field) {
        $this->field = $field;
        $this->name = $field->getFormName();
        $this->id = '_' . $this->name;
    }

    function parseValue() {
        $this->value = $this->getValue();
        if (!isset($this->value) && is_object($this->field->getAnswer()))
            $this->value = $this->field->getAnswer()->getValue();
        if (!isset($this->value) && isset($this->field->value))
            $this->value = $this->field->value;
    }

    function getValue() {
        $data = $this->field->getSource();
        // Search for HTML form name first
        if (isset($data[$this->name]))
            return $data[$this->name];
        elseif (isset($data[$this->field->get('name')]))
            return $data[$this->field->get('name')];
        elseif (isset($data[$this->field->get('id')]))
            return $data[$this->field->get('id')];
        return null;
    }

    /**
     * getJsValueGetter
     *
     * Used with the dependent fields feature, this function should return a
     * single javascript expression which can be used in a larger expression
     * (<> == true, where <> is the result of this function). The %s token
     * will be replaced with a jQuery variable representing this widget.
     */
    function getJsValueGetter() {
        return '%s.val()';
    }
}

class TextboxWidget extends Widget {
    static $input_type = 'text';

    function render($options=array()) {
        $config = $this->field->getConfiguration();
        if (isset($config['size']))
            $size = "size=\"{$config['size']}\"";
        if (isset($config['length']) && $config['length'])
            $maxlength = "maxlength=\"{$config['length']}\"";
        if (isset($config['classes']))
            $classes = 'class="'.$config['classes'].'"';
        if (isset($config['autocomplete']))
            $autocomplete = 'autocomplete="'.($config['autocomplete']?'on':'off').'"';
        if (isset($config['disabled']))
            $disabled = 'disabled="disabled"';
        if (isset($config['translatable']) && $config['translatable'])
            $translatable = 'data-translate-tag="'.$config['translatable'].'"';
        $placeholder = sprintf('placeholder="%s"', $this->field->getLocal('placeholder',
            $config['placeholder']));
        ?>
        <input type="<?php echo static::$input_type; ?>"
            id="<?php echo $this->id; ?>"
            <?php echo implode(' ', array_filter(array(
                $size, $maxlength, $classes, $autocomplete, $disabled,
                $translatable, $placeholder))); ?>
            name="<?php echo $this->name; ?>"
            value="<?php echo Format::htmlchars($this->value); ?>"/>
        <?php
    }
}

class PasswordWidget extends TextboxWidget {
    static $input_type = 'password';

    function parseValue() {
        // Show empty box unless failed POST
        if ($_SERVER['REQUEST_METHOD'] == 'POST'
                && $this->field->getForm()->isValid())
            parent::parseValue();
        else
            $this->value = '';
    }
}

class TextareaWidget extends Widget {
    function render($options=array()) {
        $config = $this->field->getConfiguration();
        $class = $cols = $rows = $maxlength = "";
        if (isset($config['rows']))
            $rows = "rows=\"{$config['rows']}\"";
        if (isset($config['cols']))
            $cols = "cols=\"{$config['cols']}\"";
        if (isset($config['length']) && $config['length'])
            $maxlength = "maxlength=\"{$config['length']}\"";
        if (isset($config['html']) && $config['html']) {
            $class = array('richtext', 'no-bar');
            $class[] = @$config['size'] ?: 'small';
            $class = sprintf('class="%s"', implode(' ', $class));
            $this->value = Format::viewableImages($this->value);
        }
        if (isset($config['translatable']) && $config['translatable']) {
            $translatable = 'data-translate-tag="'.$config['translatable'].'"';
		} 
        ?>
        <span style="display:inline-block;width:100%">
        <textarea <?php echo $rows." ".$cols." ".$maxlength." ".$class
                .' placeholder="'.$config['placeholder'].'" '.$translatable; ?>
            id="<?php echo $this->id; ?>"
            name="<?php echo $this->name; ?>"><?php
                echo Format::htmlchars($this->value);
            ?></textarea>
        </span>
        <?php
    }
}

class PhoneNumberWidget extends Widget {
    function render($options=array()) {
        $config = $this->field->getConfiguration();
        list($phone, $ext) = explode("X", $this->value);
        ?>
        <input id="<?php echo $this->id; ?>" type="text" name="<?php echo $this->name; ?>" value="<?php
        echo Format::htmlchars($phone); ?>"/><?php
        // Allow display of extension field even if disabled if the phone
        // number being edited has an extension
        if ($ext || $config['ext']) { ?> <?php echo __('Ext'); ?>:
            <input type="text" name="<?php
            echo $this->name; ?>-ext" value="<?php echo Format::htmlchars($ext);
                ?>" size="5"/>
        <?php }
    }

    function getValue() {
        $data = $this->field->getSource();
        $base = parent::getValue();
        if ($base === null)
            return $base;
        $ext = $data["{$this->name}-ext"];
        // NOTE: 'X' is significant. Don't change it
        if ($ext) $ext = 'X'.$ext;
        return $base . $ext;
    }
}

class ChoicesWidget extends Widget {
    function render($options=array()) {

        $mode = isset($options['mode']) ? $options['mode'] : null;

        if ($mode == 'view') {
            if (!($val = (string) $this->field))
                $val = sprintf('<span class="faded">%s</span>', __('None'));

            echo $val;
            return;
        }

        $config = $this->field->getConfiguration();
        if ($mode == 'search') {
            $config['multiselect'] = true;
        }

        // Determine the value for the default (the one listed if nothing is
        // selected)
        $choices = $this->field->getChoices(true);
        $prompt = ($config['prompt'])
            ? $this->field->getLocal('prompt', $config['prompt'])
            : __('Select'
            /* Used as a default prompt for a custom drop-down list */);

        $have_def = false;
        // We don't consider the 'default' when rendering in 'search' mode
        if (!strcasecmp($mode, 'search')) {
            $def_val = $prompt;
        } else {
            $def_key = $this->field->get('default');
            if (!$def_key && $config['default'])
                $def_key = $config['default'];
            if (is_array($def_key))
                $def_key = key($def_key);
            $have_def = isset($choices[$def_key]);
            $def_val = $have_def ? $choices[$def_key] : $prompt;
        }

        $values = $this->value;
        if (!is_array($values) && isset($values)) {
            $values = array($values => $this->field->getChoice($values));
        }

        if (!is_array($values))
            $values = $have_def ? array($def_key => $choices[$def_key]) : array();

        ?>
        <select name="<?php echo $this->name; ?>[]"
            id="<?php echo $this->id; ?>"
            data-placeholder="<?php echo $prompt; ?>"
            <?php if ($config['multiselect'])
                echo ' multiple="multiple" class="chosen-select"'; ?>>
            <?php if (!$have_def && !$config['multiselect']) { ?>
            <option value="<?php echo $def_key; ?>">&mdash; <?php
                echo $def_val; ?> &mdash;</option>
            <?php }
            foreach ($choices as $key => $name) {
                if (!$have_def && $key == $def_key)
                    continue; ?>
                <option value="<?php echo $key; ?>" <?php
                    if (isset($values[$key])) echo 'selected="selected"';
                ?>><?php echo $name; ?></option>
            <?php } ?>
        </select>
        <?php
        if ($config['multiselect']) {
         ?>
        <script type="text/javascript">
        $(function() {
            $("#<?php echo $this->id; ?>")
            .chosen({'disable_search_threshold':10, 'width': '250px'});
        });
        </script>
       <?php
        }
    }

    function getValue() {
        $value = parent::getValue();

        if (!$value) return null;

        // Assume multiselect
        $values = array();
        $choices = $this->field->getChoices();
        if (is_array($value)) {
            foreach($value as $k => $v) {
                if (isset($choices[$v]))
                    $values[$v] = $choices[$v];
            }
        }
        return $values;
    }

    function getJsValueGetter() {
        return '%s.find(":selected").val()';
    }
}

class CheckboxWidget extends Widget {
    function __construct($field) {
        parent::__construct($field);
        $this->name = '_field-checkboxes';
    }

    function render($options=array()) {
        $config = $this->field->getConfiguration();
        if (!isset($this->value)) {
//            $this->value = $this->field->get('default');
			$this->value = $config['default'];
        }
                ?>
        <input id="<?php echo $this->id; ?>" style="vertical-align:top;"
            type="checkbox" name="<?php echo $this->name; ?>[]" <?php
            if ($this->value) echo 'checked="checked"'; ?> value="<?php
            echo $this->field->get('id'); ?>"/>
        <?php
        if ($config['desc']) { ?>
            <em style="display:inline-block"><?php
            echo Format::viewableImages($config['desc']); ?></em>
        <?php }
    }

    function getValue() {
        $data = $this->field->getSource();
        if (count($data))
            return @in_array($this->field->get('id'), $data[$this->name]);
        return parent::getValue();
    }

    function getJsValueGetter() {
        return '%s.is(":checked")';
    }
}

class DatetimePickerWidget extends Widget {
    function render($options=array()) {
        global $cfg;

        $config = $this->field->getConfiguration();
        if ($this->value) {
            $this->value = is_int($this->value) ? $this->value :
                strtotime($this->value);

            if ($config['gmt']) {
                // Convert to GMT time
                $tz = new DateTimeZone($cfg->getTimezone());
                $D = DateTime::createFromFormat('U', $this->value);
                $this->value += $tz->getOffset($D);
            }
            list($hr, $min) = explode(':', date('H:i', $this->value));
            $this->value = Format::date($this->value, false, false, 'UTC');
        }
        ?>
        <input type="text" name="<?php echo $this->name; ?>"
            id="<?php echo $this->id; ?>"
            value="<?php echo Format::htmlchars($this->value); ?>" size="12"
            autocomplete="off" class="dp" />
        <script type="text/javascript">
            $(function() {
                $('input[name="<?php echo $this->name; ?>"]').datepicker({
                    <?php
                    if ($config['min'])
                        echo "minDate: new Date({$config['min']}000),";
                    if ($config['max'])
                        echo "maxDate: new Date({$config['max']}000),";
                    elseif (!$config['future'])
                        echo "maxDate: new Date().getTime(),";
                    ?>
                    numberOfMonths: 2,
                    showButtonPanel: true,
                    buttonImage: './images/cal.png',
                    showOn:'both',
                    dateFormat: $.translate_format('<?php echo $cfg->getDateFormat(true); ?>')
                });
            });
        </script>
        <?php
        if ($config['time'])
            // TODO: Add time picker -- requires time picker or selection with
            //       Misc::timeDropdown
            echo '&nbsp;' . Misc::timeDropdown($hr, $min, $this->name . ':time');
    }

    /**
     * Function: getValue
     * Combines the datepicker date value and the time dropdown selected
     * time value into a single date and time string value.
     */
    function getValue() {
        global $cfg;

        $data = $this->field->getSource();
        $config = $this->field->getConfiguration();
        if ($datetime = parent::getValue()) {
            $datetime = is_int($datetime) ? $datetime :
                strtotime($datetime);
            if ($datetime && isset($data[$this->name . ':time'])) {
                list($hr, $min) = explode(':', $data[$this->name . ':time']);
                $datetime += $hr * 3600 + $min * 60;
            }
            if ($datetime && $config['gmt']) {
                // Convert to GMT time
                $tz = new DateTimeZone($cfg->getTimezone());
                $D = DateTime::createFromFormat('U', $datetime);
                $datetime -= $tz->getOffset($D);
            }
        }
        return $datetime;
    }
}

class SectionBreakWidget extends Widget {
    function render($options=array()) {
        ?><div class="form-header section-break"><h3><?php
        echo Format::htmlchars($this->field->getLocal('label'));
        ?></h3><em><?php echo Format::htmlchars($this->field->getLocal('hint'));
        ?></em></div>
        <?php
    }
}

class ThreadEntryWidget extends Widget {
    function render($options=array()) {
        global $cfg;

        $object_id = false;
        if ($options['client']) {
            $namespace = $options['draft-namespace']
                ?: 'ticket.client';
             $object_id = substr(session_id(), -12);
        } else {
            $namespace = $options['draft-namespace'] ?: 'ticket.staff';
        }

        list($draft, $attrs) = Draft::getDraftAndDataAttrs($namespace, $object_id, $this->value);
        ?>
        <span class="required"><?php
            echo Format::htmlchars($this->field->getLocal('label'));
        ?>: <span class="error">*</span></span><br/>
        <textarea style="width:100%;" name="<?php echo $this->field->get('name'); ?>"
            placeholder="<?php echo Format::htmlchars($this->field->getLocal('hint')); ?>"
            class="<?php if ($cfg->isHtmlThreadEnabled()) echo 'richtext';
                ?> draft draft-delete" <?php echo $attrs; ?>
            cols="21" rows="8" style="width:80%;"><?php echo
            $draft ?: Format::htmlchars($this->value); ?></textarea>
    <?php
        $config = $this->field->getConfiguration();
        if (!$config['attachments'])
            return;

        $attachments = $this->getAttachments($config);
        print $attachments->render($options);
        foreach ($attachments->getMedia() as $type=>$urls) {
            foreach ($urls as $url)
                Form::emitMedia($url, $type);
        }
    }

    function getAttachments($config=false) {
        if (!$config)
            $config = $this->field->getConfiguration();

        $field = new FileUploadField(array(
            'id'=>'attach',
            'name'=>'attach:' . $this->field->get('id'),
            'configuration'=>$config)
        );
        $field->setForm($this->field->getForm());
        return $field;
    }
}

class FileUploadWidget extends Widget {
    static $media = array(
        'css' => array(
            '/css/filedrop.css',
        ),
    );

    function render($options) {
        $config = $this->field->getConfiguration();
        $name = $this->field->getFormName();
        $id = substr(md5(spl_object_hash($this)), 10);
        $attachments = $this->field->getFiles();
        $mimetypes = array_filter($config['__mimetypes'],
            function($t) { return strpos($t, '/') !== false; }
        );
        $files = array();
        foreach ($this->value ?: array() as $fid) {
            $found = false;
            foreach ($attachments as $f) {
                if ($f['id'] == $fid) {
                    $files[] = $f;
                    $found = true;
                    break;
                }
            }
            if (!$found && ($file = AttachmentFile::lookup($fid))) {
                $files[] = array(
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'type' => $file->getType(),
                    'size' => $file->getSize(),
                    'download_url' => $file->getDownloadUrl(),
                );
            }
        }
        ?><div id="<?php echo $id;
            ?>" class="filedrop"><div class="files"></div>
            <div class="dropzone"><i class="icon-upload"></i>
            <?php echo sprintf(
                __('Drop files here or %s choose them %s'),
                '<a href="#" class="manual">', '</a>'); ?>
        <input type="file" multiple="multiple"
            id="file-<?php echo $id; ?>" style="display:none;"
            accept="<?php echo implode(',', $config['__mimetypes']); ?>"/>
        </div></div>
        <script type="text/javascript">
        $(function(){$('#<?php echo $id; ?> .dropzone').filedropbox({
          url: 'ajax.php/form/upload/<?php echo $this->field->get('id') ?>',
          link: $('#<?php echo $id; ?>').find('a.manual'),
          paramname: 'upload[]',
          fallback_id: 'file-<?php echo $id; ?>',
          allowedfileextensions: <?php echo JsonDataEncoder::encode(
            $config['__extensions'] ?: array()); ?>,
          allowedfiletypes: <?php echo JsonDataEncoder::encode(
            $mimetypes); ?>,
          maxfiles: <?php echo $config['max'] ?: 20; ?>,
          maxfilesize: <?php echo ($config['size'] ?: 1048576) / 1048576; ?>,
          name: '<?php echo $name; ?>[]',
          files: <?php echo JsonDataEncoder::encode($files); ?>
        });});
        </script>
<?php
    }

    function getValue() {
        $data = $this->field->getSource();
        $ids = array();
        // Handle manual uploads (IE<10)
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES[$this->name])) {
            foreach (AttachmentFile::format($_FILES[$this->name]) as $file) {
                try {
                    $F = $this->field->uploadFile($file);
                    $ids[] = $F->getId();
                }
                catch (FileUploadError $ex) {}
            }
            return array_merge($ids, parent::getValue() ?: array());
        }
        // If no value was sent, assume an empty list
        elseif ($data && is_array($data) && !isset($data[$this->name]))
            return array();

        return parent::getValue();
    }
}

class FileUploadError extends Exception {}

class FreeTextField extends FormField {
    static $widget = 'FreeTextWidget';

    function getConfigurationOptions() {
        return array(
            'content' => new TextareaField(array(
                'id'=>1,
				'configuration' => array(
					'html' => true, 'size'=>'large',
					'translatable'=>$this->getTranslateTag('content'),
					),
                'label'=>__('Content'), 'required'=>true, 'default'=>'',
                'hint'=>__('Free text shown in the form, such as a disclaimer'),
            )),
        );
    }

    function hasData() {
        return false;
    }

    function isBlockLevel() {
        return true;
    }
}

class FreeTextWidget extends Widget {
    function render($options=array()) {
        $config = $this->field->getConfiguration();
        if (isset($config['translatable']) && $config['translatable']) {
            $translatable = 'data-translate-tag="'.$config['translatable'].'"';
		}
        ?><div id="<?php
	        echo $this->id;
        ?>"><?php
        if ($label = $this->field->getLocal('label')) { ?>
            <h3><?php
            echo Format::htmlchars($label);
        ?></h3><?php
        }
        if ($hint = $this->field->getLocal('hint')) { ?>
        <em><?php
            echo Format::htmlchars($hint);
        ?></em><?php
        } ?>
        <div <?php echo $translatable; ?>><?php
            echo Format::viewableImages($config['content']); ?></div>
        </div>
        <?php
    }
}

class VisibilityConstraint {

    const HIDDEN =      0x0001;
    const VISIBLE =     0x0002;

    var $initial;
    var $constraint;

    function __construct($constraint, $initial=self::VISIBLE) {
        $this->constraint = $constraint;
        $this->initial = $initial;
    }

    function emitJavascript($field) {
        $func = 'recheck';
        $form = $field->getForm();
?>
    <script type="text/javascript">
      !(function() {
        var <?php echo $func; ?> = function() {
          var target = $('#field<?php echo $field->getWidget()->id; ?>');

<?php   $fields = $this->getAllFields($this->constraint);
        foreach ($fields as $f) {
            $field = $form->getField($f);
            echo sprintf('var %1$s = $("#%1$s");',
                $field->getWidget()->id);
        }
        $expression = $this->compileQ($this->constraint, $form);
?>
          if (<?php echo $expression; ?>)
            target.slideDown('fast', function (){
                $(this).trigger('show');
                });
          else
            target.slideUp('fast', function (){
                $(this).trigger('hide');
                });
        };

<?php   foreach ($fields as $f) {
            $w = $form->getField($f)->getWidget();
?>
        $('#<?php echo $w->id; ?>').on('change', <?php echo $func; ?>);
        $('#field<?php echo $w->id; ?>').on('show hide', <?php
                echo $func; ?>);
<?php   } ?>
      })();
    </script><?php
    }

    /**
     * Determines if the field was visible when the form was submitted
     */
    function isVisible($field) {
        return $this->compileQPhp($this->constraint, $field);
    }

    function compileQPhp(Q $Q, $field) {
        if (!($form = $field->getForm())) {
            return $this->initial == self::VISIBLE;
        }
        $expr = array();
        foreach ($Q->constraints as $c=>$value) {
            if ($value instanceof Q) {
                $expr[] = $this->compileQPhp($value, $field);
            }
            else {
                @list($f, $op) = explode('__', $c, 2);
                $field = $form->getField($f);
                $wval = $field->getClean();
                switch ($op) {
                case 'eq':
                case null:
                    $expr[] = ($wval == $value && $field->isVisible());
                }
            }
        }
        $glue = $Q->isOred()
            ? function($a, $b) { return $a || $b; }
            : function($a, $b) { return $a && $b; };
        $initial = !$Q->isOred();
        $expression = array_reduce($expr, $glue, $initial);
        if ($Q->isNegated)
            $expression = !$expression;
        return $expression;
    }

    function getAllFields(Q $Q, &$fields=array()) {
        foreach ($Q->constraints as $c=>$value) {
            if ($c instanceof Q) {
                $this->getAllFields($c, $fields);
            }
            else {
                list($f, $op) = explode('__', $c, 2);
                $fields[$f] = true;
            }
        }
        return array_keys($fields);
    }

    function compileQ($Q, $form) {
        $expr = array();
        foreach ($Q->constraints as $c=>$value) {
            if ($value instanceof Q) {
                $expr[] = $this->compileQ($value, $form);
            }
            else {
                list($f, $op) = explode('__', $c, 2);
                $widget = $form->getField($f)->getWidget();
                $id = $widget->id;
                switch ($op) {
                case 'eq':
                case null:
                    $expr[] = sprintf('(%s.is(":visible") && %s)',
                            $id,
                            sprintf('%s == %s',
                                sprintf($widget->getJsValueGetter(), $id),
                                JsonDataEncoder::encode($value))
                            );
                }
            }
        }
        $glue = $Q->isOred() ? ' || ' : ' && ';
        $expression = implode($glue, $expr);
        if (count($expr) > 1)
            $expression = '('.$expression.')';
        if ($Q->isNegated)
            $expression = '!'.$expression;
        return $expression;
    }
}

?>
