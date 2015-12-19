<?php
if(!defined('OSTCLIENTINC') || !$thisclient || !$ticket || !$ticket->checkUserAccess($thisclient)) die('Access Denied!');

$info=($_POST && $errors)?Format::htmlchars($_POST):array();

$dept = $ticket->getDept();

if ($ticket->isClosed() && !$ticket->isReopenable())
    $warn = __('This ticket is marked as closed and cannot be reopened.');

//Making sure we don't leak out internal dept names
if(!$dept || !$dept->isPublic())
    $dept = $cfg->getDefaultDept();

if ($thisclient && $thisclient->isGuest()
    && $cfg->isClientRegistrationEnabled()) { ?>

<div id="msg_info">
    <i class="icon-compass icon-2x pull-left"></i>
    <strong><?php echo __('Looking for your other tickets?'); ?></strong></br>
    <a href="<?php echo ROOT_PATH; ?>login.php?e=<?php
        echo urlencode($thisclient->getEmail());
    ?>" style="text-decoration:underline"><?php echo __('Sign In'); ?></a>
    <?php echo sprintf(__('or %s register for an account %s for the best experience on our help desk.'),
        '<a href="account.php?do=create" style="text-decoration:underline">','</a>'); ?>
    </div>

<?php } ?>

<table width="100%" cellpadding="1" cellspacing="0" border="0" id="ticketInfo">
    <tr>
        <td colspan="2" width="100%">
            <h1>
                <?php echo sprintf(__('Ticket #%s'), $ticket->getNumber()); ?> &nbsp;
                <a href="tickets.php?id=<?php echo $ticket->getId(); ?>" title="<?php echo __('Reload'); ?>"><span class="Icon refresh">&nbsp;</span></a>
<div class="pull-right">
    <a class="action-button" href="tickets.php?a=print&id=<?php
        echo $ticket->getId(); ?>"><i class="icon-print"></i> <?php echo __('Print'); ?></a>
<?php if ($ticket->hasClientEditableFields()
        // Only ticket owners can edit the ticket details (and other forms)
        && $thisclient->getId() == $ticket->getUserId()) { ?>
                <a class="action-button pull-right" href="tickets.php?a=edit&id=<?php
                     echo $ticket->getId(); ?>"><i class="icon-edit"></i> <?php echo __('Edit'); ?></a>
<?php } ?>
</div>
            </h1>
        </td>
    </tr>
    <tr>
        <td width="50%">
            <table class="infoTable" cellspacing="1" cellpadding="3" width="100%" border="0">
                <tr>
                    <th width="100"><?php echo __('Ticket Status');?> :</th>
                    <td><?php echo ($S = $ticket->getStatus()) ? $S->getLocalName() : ''; ?></td>
                </tr>
                <tr>
                    <th><?php echo __('Department');?> :</th>
                    <td><?php echo Format::htmlchars($dept instanceof Dept ? $dept->getName() : ''); ?></td>
                </tr>
                <tr>
                    <th><?php echo __('Create Date');?> :</th>
                    <td><?php echo Format::datetime($ticket->getCreateDate()); ?></td>
                </tr>
           </table>
       </td>
       <td width="50%">
           <table class="infoTable" cellspacing="1" cellpadding="3" width="100%" border="0">
               <tr>
                   <th width="100"><?php echo __('Name');?> :</th>
                   <td><?php echo mb_convert_case(Format::htmlchars($ticket->getOwner()->getFirstName()." ".$ticket->getName()), MB_CASE_TITLE); ?></td>
               </tr>
               <tr>
                   <th width="100"><?php echo __('Email');?> :</th>
                   <td><?php echo Format::htmlchars($ticket->getEmail()); ?></td>
               </tr>
               <tr>
                   <th><?php echo __('Phone');?> :</th>
                   <td><?php echo $ticket->getPhoneNumber(); ?></td>
               </tr>
            </table>
       </td>
    </tr>
    <tr>
<?php
foreach (DynamicFormEntry::forTicket($ticket->getId()) as $idx=>$form) {
    $answers = $form->getAnswers();
    if ($idx > 0 and $idx % 2 == 0) { ?>
        </tr><tr>
    <?php } ?>
    <td width="50%">
        <table class="infoTable" cellspacing="1" cellpadding="3" width="100%" border="0">
    <?php foreach ($answers as $answer) {
        if (in_array($answer->getField()->get('name'), array('name', 'email', 'subject')))
            continue;
        elseif (!$answer->getField()->isVisibleToUsers())
            continue;
        ?>
        <tr>
        <th width="100"><?php echo $answer->getField()->getLocal('label');
            ?> :</th>
        <td><?php echo $answer->display(); ?></td>
        </tr>
    <?php } ?>
    </table></td>
<?php } ?>
</tr>
</table>
<br>
<div class="subject"><?php echo __('Subject'); ?> : <strong><?php echo Format::htmlchars($ticket->getSubject()); ?></strong></div>
<div id="ticketThread">
<?php
if($ticket->getThreadCount() && ($thread=$ticket->getClientThread())) {
    $threadType=array('M' => 'message', 'R' => 'response');
    foreach($thread as $entry) {

        //Making sure internal notes are not displayed due to backend MISTAKES!
        if(!$threadType[$entry->type]) continue;
        $poster = $entry->poster;
        if($entry->type=='R' && ($cfg->hideStaffName() || !$entry->staff_id))
            $poster = ' ';
        ?>
        <table class="thread-entry <?php echo $threadType[$entry->type]; ?>" cellspacing="0" cellpadding="1" width="100%" border="0">
            <tr><th><div>
<?php echo Format::datetime($entry->created); ?>
                &nbsp;&nbsp;<span class="textra"></span>
                <span><?php echo $poster; ?></span>
            </div>
            </th></tr>
            <tr><td class="thread-body"><div><?php echo Format::clickableurls($entry->getBody()->toHtml()); ?></div></td></tr>
            <?php
            if($entry->has_attachments
                    && ($urls = $entry->getAttachmentUrls())
                    && ($links = $entry->getAttachmentsLinks())) { ?>
                <tr><td class="info"><?php echo $links; ?></td></tr>
<?php       }
            if ($urls) { ?>
                <script type="text/javascript">
                    $(function() { showImagesInline(<?php echo
                        JsonDataEncoder::encode($urls); ?>); });
                </script>
<?php       } ?>
        </table>
    <?php
    }
}
?>
</div>
<div class="clear" style="padding-bottom:10px;"></div>
<?php if($errors['err']) { ?>
    <div id="msg_error"><?php echo $errors['err']; ?></div>
<?php }elseif($msg) { ?>
    <div id="msg_notice"><?php echo $msg; ?></div>
<?php }elseif($warn) { ?>
    <div id="msg_warning"><?php echo $warn; ?></div>
<?php } ?>

<?php

if (!$ticket->isClosed() || $ticket->isReopenable()) { ?>
<form id="reply" action="tickets.php?id=<?php echo $ticket->getId(); ?>#reply" name="reply" method="post" enctype="multipart/form-data">
    <?php csrf_token(); ?>
    <h2><?php echo __('Post a Reply');?></h2>
    <input type="hidden" name="id" value="<?php echo $ticket->getId(); ?>">
    <input type="hidden" name="a" value="reply">
    <table border="0" cellspacing="0" cellpadding="3" style="width:100%">
        <tr>
            <td colspan="2">
                <?php
                if($ticket->isClosed()) {
                    $msg='<b>'.__('Ticket will be reopened on message post').'</b>';
                } else {
                    $msg=__('To best assist you, we request that you be specific and detailed');
                }
                ?>
                <span id="msg"><em><?php echo $msg; ?> </em></span><font class="error">*&nbsp;<?php echo $errors['message']; ?></font>
                <br/>
                <textarea name="message" id="message" cols="50" rows="9" wrap="soft"
                    class="<?php if ($cfg->isHtmlThreadEnabled()) echo 'richtext';
                        ?> draft" <?php
    list($draft, $attrs) = Draft::getDraftAndDataAttrs('ticket.client', $ticket->getId(), $info['message']);
    echo $attrs; ?>><?php echo $draft ?: $info['message'];
                ?></textarea>
        <?php
        if ($messageField->isAttachmentsEnabled()) { ?>
<?php
            print $attachments->render(array('client'=>true));
?>
        <?php
        } ?>
            </td>
        </tr>
    </table>
    <p style="padding-left:165px;">
        <input type="submit" value="<?php echo __('Post Reply');?>">
        <input type="reset" value="<?php echo __('Reset');?>">
        <input type="button" value="<?php echo __('Cancel');?>" onClick="history.go(-1)">
    </p>
</form>
<?php
} ?>
