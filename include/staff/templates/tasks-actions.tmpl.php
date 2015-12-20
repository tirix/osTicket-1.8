<?php
// Tasks' mass actions based on logged in agent

$actions = array();

if ($agent->hasPerm(Task::PERM_CLOSE, false)) {

    if (isset($options['status'])) {
        $status = $options['status'];
    ?>
        <span
            class="action-button"
            data-dropdown="#action-dropdown-tasks-status">
            <i class="icon-caret-down pull-right"></i>
            <a class="tasks-status-action"
                href="#statuses"><i
                class="icon-flag"></i> <?php
                echo __('Change Status'); ?></a>
        </span>
        <div id="action-dropdown-tasks-status"
            class="action-dropdown anchor-right">
            <ul>
                <?php
                if (!$status || !strcasecmp($status, 'closed')) { ?>
                <li>
                    <a class="no-pjax tasks-action"
                        href="#tasks/mass/reopen"><i
                        class="icon-fixed-width icon-undo"></i> <?php
                        echo __('Reopen');?> </a>
                </li>
                <?php
                }
                if (!$status || !strcasecmp($status, 'open')) {
                ?>
                <li>
                    <a class="no-pjax tasks-action"
                        href="#tasks/mass/close"><i
                        class="icon-fixed-width icon-ok-circle"></i> <?php
                        echo __('Close');?> </a>
                </li>
                <?php
                } ?>
            </ul>
        </div>
<?php
    } else {

        $actions += array(
                'reopen' => array(
                    'icon' => 'icon-undo',
                    'action' => __('Reopen')
                ));

        $actions += array(
                'close' => array(
                    'icon' => 'icon-ok-circle',
                    'action' => __('Close')
                ));
    }
}

if ($agent->hasPerm(Task::PERM_ASSIGN, false)) {
    $actions += array(
            'assign' => array(
                'icon' => 'icon-user',
                'action' => __('Assign')
            ));
}

if ($agent->hasPerm(Task::PERM_TRANSFER, false)) {
    $actions += array(
            'transfer' => array(
                'icon' => 'icon-share',
                'redirect' => 'tickets.php',
                'action' => __('Transfer')
            ));
}

if ($agent->hasPerm(Task::PERM_DELETE, false)) {
    $actions += array(
            'delete' => array(
                'class' => 'danger',
                'icon' => 'icon-trash',
                'action' => __('Delete')
            ));
}
if ($actions) {
    $more = $options['morelabel'] ?: __('More');
    ?>
    <span
        class="action-button"
        data-dropdown="#action-dropdown-moreoptions">
        <i class="icon-caret-down pull-right"></i>
        <a class="tasks-action"
            href="#moreoptions"><i
            class="icon-reorder"></i> <?php
            echo $more; ?></a>
    </span>
    <div id="action-dropdown-moreoptions"
        class="action-dropdown anchor-right">
        <ul>
    <?php foreach ($actions as $a => $action) { ?>
            <li <?php
                if ($action['class'])
                    echo sprintf("class='%s'", $action['class']); ?> >
                <a class="no-pjax tasks-action"
                    <?php
                    if ($action['dialog'])
                        echo sprintf("data-dialog-config='%s'", $action['dialog']);
                    if ($action['redirect'])
                        echo sprintf("data-redirect='%s'", $action['redirect']);
                    ?>
                    href="<?php
                    echo sprintf('#tasks/mass/%s', $a); ?>"
                    ><i class="icon-fixed-width <?php
                    echo $action['icon'] ?: 'icon-tag'; ?>"></i> <?php
                    echo $action['action']; ?></a>
            </li>
        <?php
        } ?>
        </ul>
    </div>
 <?php
 } ?>
<script type="text/javascript">
$(function() {
    $(document).off('.tasks-actions');
    $(document).on('click.tasks-actions', 'a.tasks-action', function(e) {
        e.preventDefault();
        var count = checkbox_checker($('form#tasks'), 1);
        if (count) {
            var url = 'ajax.php/'
            +$(this).attr('href').substr(1)
            +'?count='+count
            +'&_uid='+new Date().getTime();
            $.dialog(url, [201], function (xhr) {
                $.pjax.reload('#pjax-container');
             });
        }
        return false;
    });
});
</script>
