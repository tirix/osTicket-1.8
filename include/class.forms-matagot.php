<?php
require_once(INCLUDE_DIR .'class.filter.php');
define('CODE_TABLE','mtg_code');

FormField::addFieldTypes(/* @trans */ 'Matagot Custom', array('PropertyDisplayField', 'getFieldTypes'));

class FieldChoiceField extends ChoiceField {
	var $_content = array();
	
	function getConfigurationOptions() {
		return array(
				'prompt' => new TextboxField(array(
						'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
						'hint'=>__('Leading text shown before a value is selected'),
						'configuration'=>array('size'=>40, 'length'=>40,
								'translatable'=>$this->getTranslateTag('prompt'),
						),
				)),
		);
	}

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
        $choices = array();
		if($matches = Filter::getSupportedMatches()) 
			if($customFormFields = $matches['Custom Forms']){
				foreach ($customFormFields as $id => $name)
					if(preg_match('/field\.(\d+).(\d+)/', $id, $field_id))
						$choices[$field_id[0]] = $name;
			}
		//error_log('Returning matches: '.print_r($matches, true));
		//error_log('Form: '.print_r($this->getForm()->config, true));
		///error_log('Choices: '.print_r($customFormFields, true));
		return $choices;
	}

    function searchable($value) {
        return null;
    }
}

class PropertyDisplayField extends FormField {
	static $widget = 'PropertyDisplayWidget';
	
	function getConfigurationOptions() {
		return array(
			'field' => new FieldChoiceField(array(
				'id'=>2,
				'label'=>__('Field'),
				'hint'=>__('Choose the field depending on which this information will change'),
				)),
            'display' => new ChoiceField(array(
                'id'=>3, 
            	'label'=>__('Display'), 'required'=>true, 'default'=>'text',
                'choices' => array('text'=>__('Text'),'image'=>__('Image URL'),
				))),
			);
	}
	
	function hasData() {
		return false;
	}
	
	function isBlockLevel() {
		return true;
	}
	
	function getMasterFieldId() {
    	if (!$this->_masterFieldId) {
			$config = $this->getConfiguration();
			if(preg_match('/field\.(\d+)\.(\d+)/', $config['field'], $field_matches)) {
				$this->_masterFieldId = $field_matches[1]; //substr(md5(session_id() . '-field-id-'.$field_matches[1]), -16);
			}
    	}
		return $this->_masterFieldId;
    	}

    function getMasterFieldFormName() {
    	return substr(md5(session_id() . '-field-id-'.$this->getMasterFieldId()), -16);
    }
    	
    function getDisplayFieldName($id) {
    	return substr(md5(session_id() . '-pdisp-'.$this->get('id').'-'.$id), -16);
    }
    	
    function getContents() {
    	if (count($this->_content) == 0) {
			$config = $this->getConfiguration();
			if(preg_match('/field\.(\d+)\.(\d+)/', $config['field'], $field_matches)) 
			{
				$property_field_id = $field_matches[2];
				$master_field = DynamicFormField::lookup($this->getMasterFieldId());
        		list(,$list_id) = explode('-', $master_field->get('type'));
        		$list = DynamicList::lookup($list_id);
        		foreach($list->getItems()->all() as $item) {
					$item_config = $item->getConfiguration();
					if($content = $item_config[$property_field_id])
        			$this->_content[$item->get('id')] = array(
        				'id' => $item->get('id'),
        				'label' => $item->getValue(),
						'content' => $content,
        				'localContent' => $item->getLocalProperty($property_field_id),
        				//'translatable' => $item->getPropertyTranslateTag($property_field_id)
        			);
        		}
			}
    	}
		return $this->_content;
	}
	
    function display($value) {
        $config = $this->getConfiguration();
        if ($config['html'])
            return Format::safe_html($value);
        else
            return nl2br(Format::htmlchars($value));
    }

	static function getFieldTypes() {
		return array(
				'dfield' => array(	/* @trans */ "Property Display", "PropertyDisplayField", 1),
            	'sinfo' => array(   /* @trans */ 'Information++', 'SuperTextField', 2),
				'cinput' => array(   /* @trans */ 'Code Input', 'CodeInputField', 3),
				'lang' => array(   /* @trans */ 'Language', 'LanguageField', 4),
		);
	}
}

class PropertyDisplayWidget extends Widget {
    function render($options=array()) {
        $config = $this->field->getConfiguration();
        if (isset($config['translatable']) && $config['translatable']) {
            $translatable = 'data-translate-tag="'.$config['translatable'].'"';
		} ?>
        <div id="<?php  echo $this->id; ?>"> <?php 
	        foreach($this->field->getContents() as $item)
	        { ?>
				<div id="_<?php echo $this->field->getDisplayFieldName($item['id']); ?>" style="display:none;"> <?php 
	        	if($config['display'] == 'text') {
					if ($label = $this->field->getLocal('label')) { ?>
			            <h3><?php echo Format::htmlchars($label.' &mdash; '.$item['label']); ?></h3><?php
			        }
			        if ($hint = $this->field->getLocal('hint')) { ?>
				        <em><?php echo Format::htmlchars($hint); ?></em><?php
			        } ?>
			        <div> <?php echo nl2br(Format::htmlchars($item['localContent'])); ?> </div><?php
		        }
		        if($config['display'] == 'image') { 
					$url = preg_replace('/\%\{lang\}/', Internationalization::getCurrentLanguage(true), $item['content']); ?>
			        <img src="<?php echo Format::htmlchars($url); ?>" width="100%"/><?php
		        } ?>
		        </div><?php 
	    	} ?>
        </div>
        <script type="text/javascript">
			var masterField = $('#_<?php echo $this->field->getMasterFieldFormName(); ?>');
			masterField.change(function() { 
				pDispDiv = $('#_'+getPropertyDisplayFieldName(<?php  echo $this->field->get('id'); ?>, this.value));
				pDispDiv.show(500);
				$('#_<?php echo $this->field->getFormName(); ?> > div').not(pDispDiv).hide(500);
			});
			masterField.change();
        </script>
        <?php 
	    //echo "<pre>".print_r($this->field->getContents())."</pre>";
    }
}

class LanguageField extends ChoiceField {
	static $widget = 'LanguageChoicesWidget';
}

class LanguageChoicesWidget extends ChoicesWidget {
    function render($options=array()) {
		$this->field->set('default', substr($_GET['lang'],0,2));
		return parent::render($options);
    }
}

class CodeInputField extends TextboxField {
	
	function getConfigurationOptions() {
		return array(
			'invalid-code-error' => new TextboxField(array(
				'id'=>1, 
				'label'=>__('Invalid Code Error'), 
				'default'=>'',
				'configuration'=>array(
					'size'=>40, 
					'length'=>60,
					'translatable'=>$this->getTranslateTag('invalid-code-error')
					),
				'hint'=>__('Message shown to user if the code does not exist'))),
			'used-code-error' => new TextboxField(array(
					'id'=>2,
					'label'=>__('Already Used Code Error'),
					'default'=>'',
					'configuration'=>array(
							'size'=>40,
							'length'=>60,
							'translatable'=>$this->getTranslateTag('used-code-error')
					),
					'hint'=>__('Message shown to user if the code has already been redeemed'))),
			);
		}
	
	function validateEntry($value) {
		parent::validateEntry($value);
		$config = $this->getConfiguration();

		// check the code validity
		$code = Code::lookup($value);
		if($code == null) 
			$this->_errors[] = $this->getLocal('invalid-code-error', $config['invalid-code-error']);
		else if($code->get('ticket_id') != null)
			$this->_errors[] = $this->getLocal('used-code-error', $config['used-code-error']);
	}
}

class Code extends VerySimpleModel {

	static $meta = array(
			'table' => CODE_TABLE,
			'pk' => array('code'),
	);

	function getHashtable() {
		return $this->ht;
	}

	function getInfo() {
		return $this->getHashtable();
	}

	function getTicketId() {
		return $this->ticket_id;
	}

	function getCampaignId() {
		return $this->campaign_id;
	}

    static function lookup($code) {
        return parent::lookup($code);
    }

}




class FA_ForwardToAsmodee extends TriggerAction {
	static $type = 'forward';
	static $name = /* @trans */ 'Forward to Asmodee Form';

	function apply(&$ticket, array $info) {
		// Create and verify the dynamic form entry for the new ticket
		$config = $this->getConfiguration();
		$form_url = $config['form_url'];
		if(!$form_url) $form_url = '/support/asmodeeform.php';

		$ticket['forwardToUrl']=$form_url;
	}

	static function forwardToUrl($ticket, $form_url) {
		$asmodeeFields = FA_ForwardToAsmodee::fillInAsmodeeFieldsFrench($ticket);
		foreach ( $asmodeeFields as $key => $value)
			$post_items[] = $key . '=' . $value;
		$post_string = implode ('&', $post_items);
		
		//error_log("Sending POST string: ".$post_string);
		
		$curl_connection = curl_init($form_url);
		curl_setopt($curl_connection, CURLOPT_POST, 1);
		curl_setopt($curl_connection, CURLOPT_POSTFIELDS,$post_string);
		curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($curl_connection, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
		curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, 1);
		
		$result=curl_exec ($curl_connection);
		curl_close ($curl_connection);

        //Log an internal note
		array_walk($asmodeeFields, function($value, $key) { return htmlentities($value); });
        $ticket->logActivity("Forwarded to Asmodee", 
        	"Ticket was formarded to Asmodee with following fields:<br/><pre>"
        	.print_r($asmodeeFields, true)
        	."</pre>");
		}
	
	static function getAsmodeeIssueType($issueTypeEntry) {
		$issueTypeToAsmodee = array(
				'SUPP-001' => 2, // 15 piece manquante
				'SUPP-002' => 1, // 14 piece endommagee
				'SUPP-003' => 2, // 18 mauvaise piece
				'CUST-001' => 1, // 17 piece endommagee apres usage
				'CUST-002' => 3, // 16 piece perdue
				'CUST-003' => 5, // 161 free promo item
		);
		$listItemId = false;
		foreach($issueTypeEntry->getValue() as $id=>$value) $listItemId = $id;
		$issueAbbrev = $issueTypeEntry->getField()->getList()->getItem($listItemId)->getAbbrev();
		return $issueTypeToAsmodee[$issueAbbrev];
	}
	
	static function fillInAsmodeeFieldsFrench(Ticket $ticket) {
		$details = 'Bonjour,\r\n'
			.'Ce ticket cree via le service SAV Matagot concerne le jeu '.$ticket->getVar('game')->toString().', gere par le SAV Asmodee.\r\n' // �
			.'Pour reference, son identifiant dans le service SAV Matagot est '.$ticket->getNumber().'\r\n'
			.'Il concerne:\r\n';
		$issueTypeToAsmodee = FA_ForwardToAsmodee::getAsmodeeIssueType($ticket->getVar('issue1'));
		for($i=1;$i<6;$i++) {
			if($ticket->getVar('type'.$i)->toString()) {
				$details .= "- ".$ticket->getVar('issue'.$i)->toString();
				$details .= ": ".$ticket->getVar('count'.$i)->toString();
				$details .= " ".$ticket->getVar('desc'.$i)->toString();
				$details .= "\r\n";
				}
			}
		$details .= 'Cordialement, \r\nL\'equipe de support Matagot.\r\n\r\n';
		$details .= 'Ci-dessous commentaires ajoutes par l\'utilisateur:\r\n=================\r\n';
		$details .= $ticket->getVar('comments');

		$user = $ticket->getOwner();
		$address = explode("\n", $user->getVar('address')->toString());
		$fields = array(
				'nom' =>$user->getName()->getFull(),
				'prenom' => $user->getFirstName()->toString(),
				'societe' => '',
				'adresse1' => array_shift($address),
				'adresse2' => isset($address[0])?array_shift($address):'',
				'adresse3' => isset($address[0])?implode('   ', $address):'',
				'code_postal' => $user->getVar('zip')->toString(),
				'ville' => $user->getVar('city')->toString(),
				'pays' => $user->getVar('country')->toString(),
				'email' => $user->getEmail(),
				'confirm_email' => $user->getEmail(),
				'telephone' => $user->getVar('phone')->toString(),
				'demande' => $issueTypeToAsmodee,
				'jeu_concerne' => $ticket->getVar('game')->toString(),
				'details' => $details,
				'lang' => 'fr'
		);
		array_walk($fields, function($value, $key) { return urlencode($value); });
		return $fields;
	}
	
	function getConfigurationOptions() {
		return array(
			'form_url' => new TextboxField(array(
				'id'=>2,
				'label'=>__('Form URL'), 
				'required'=>true, 
				'default'=>'',
				'hint'=>__('The URL of the Asmodee Form'),
				'configuration'=>array('size'=>60, 'length'=>128),
			)),
		);
	}
}

FilterAction::register('FA_ForwardToAsmodee', /* @trans */ 'Matagot Custom');

/*
	// Not used - we now have the ticket object after it's created, not just the variables.
	function unpackTicket(&$vars) {
		$interesting = array('firstname', 'address', 'zip', 'city', 'country', 'phone');
		$user_form = UserForm::getUserForm()->getForm($vars);
		// Add all the user-entered info for filtering
		foreach ($user_form->getFields() as $f) {
			if (in_array($f->get('name'), $interesting))
				$vars[$f->get('name')] = $vars['field.'.$f->get('id')];
		}

		$interesting = array('game', 'issue1', 'count1', 'desc1', 'issue2', 'count2', 'desc2', 'issue3', 'count3', 'desc3', 'issue4', 'count4', 'desc4', 'issue5', 'count5', 'desc5','comments');
		$piece_request_form = DynamicForm::lookup(9); // TODO get form dynamically ?
		// Add all the user-entered info for filtering
		foreach ($piece_request_form->getFields() as $f) {
			if (in_array($f->get('name'), $interesting)) {
				$vars[$f->get('name')] = $vars['field.'.$f->get('id')];
				if(isset($vars['field.'.$f->get('id').'.abb'])) 
					$vars[$f->get('name').'.abb'] = $vars['field.'.$f->get('id').'.abb'];
				}
		}
	}
	



class SuperTextField extends FormField {
    static $widget = 'SuperTextWidget';

    function getConfigurationOptions() {
        return array(
            'content' => new TextareaField(array(
                'id'=>1,
				'configuration' => array(
					'html' => true, 'size'=>'large',
					'translatable'=>$this->getTranslateTag('placeholder'),
					),
                'label'=>__('Content'), 'required'=>true, 'default'=>'',
                'hint'=>__('Free text shown in the form, such as a disclaimer'),
            )),
			'class'=>new TextboxField(array(
				'id'=>2, 'label'=>__('CSS Class'), 'default'=>'',
				'configuration'=>array('size'=>40, 'length'=>60),
				'hint'=>__('The CSS class of the information field'),
			)),
			'imgsrc'=>new TextboxField(array(
					'id'=>3, 'label'=>__('Image URL'), 'default'=>'',
					'configuration'=>array('size'=>40, 'length'=>60),
					'hint'=>__('The URL of the image to display in the information field'),
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

class SuperTextWidget extends Widget {
    function render($options=array()) {
        $config = $this->field->getConfiguration();
        if (isset($config['translatable']) && $config['translatable']) {
            $translatable = 'data-translate-tag="'.$config['translatable'].'"';
		}
        ?><div class="<?php 
            echo Format::htmlchars($config['class']);
        ?>" id="<?php
	        echo $this->id;
        ?>"><?php
        if ($label = $this->field->getLocal('label')) { ?>
            <h3><?php
            echo Format::htmlchars($label);
        ?></h3><?php
        }
        if($imgsrc = $config['imgsrc']){ ?>
        <img src="<?php
            echo Format::htmlchars($imgsrc);;
        ?>" /><?php
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
*/

/*
function expandOnSelect(fieldId, listItemId, expandFieldId) {
	$("#_"+getFieldName(fieldId)).change( function() {
		if(this.value == listItemId) {
			//console.log("Showing :"+expandFieldId);
			$("#_"+getFieldName(expandFieldId)).show(500);
		}
		else {
			$("#_"+getFieldName(expandFieldId)).hide(500);
		}
	}
		);
}

function newHelpTopic() {
	$('.expandable').hide();
	expandOnSelect(40, 62, 61); // Cyclades
	expandOnSelect(40, 3, 62); // Geants
	expandOnSelect(40, 72, 60); // Kemet
}

			var masterField = $('#_<?php echo $this->field->getMasterFieldFormName(); ?>');
			var matagotHandlers = masterField.prop('matagotHandlers');
			if(!matagotHandlers) matagotHandlers = {};
			matagotHandlers['<?php echo $this->field->getFormName(); ?>'] = function(field) { 
				pDispDiv = $('#_'+getPropertyDisplayFieldName(<?php  echo $this->field->get('id'); ?>, field.value));
				pDispDiv.show(500);
				$('#_<?php echo $this->field->getFormName(); ?> > div').not(pDispDiv).hide(500);
			};
			masterField.prop('matagotHandlers', matagotHandlers);
			masterField.off('change');
			masterField.change(function() { 
				var handlers = $(this).prop('matagotHandlers'); 
				for(var id in handlers) handlers[id](this); 
				});
			masterField.change();

*/

?>