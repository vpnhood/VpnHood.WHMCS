<?php
use WHMCS\Database\Capsule;

if (!defined('WHMCS'))
    die('You cannot access this file directly.');

add_hook('ClientAreaPrimarySidebar', 100, function ($primarySidebar) {

    // Remove child menus
    // Remove children from the Account menu
    $accountMenu = $primarySidebar->getChild('Account');
    if (!is_null($accountMenu)) {
        $itemsToRemove = ['Contacts/Sub-Accounts'];
        foreach ($itemsToRemove as $item) {
            if ($accountMenu->getChild($item)) {
                $accountMenu->removeChild($item);
            }
        }
    }


    $serviceId = (int)$_REQUEST['id'];

    if ($serviceId > 0 && !is_null($primarySidebar->getChild('Service Details Actions'))) {

        $serviceData = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->select('domainstatus', 'regdate')
            ->first();

        $isServiceActivated = $serviceData->domainstatus === 'Active';

        $registrationTimestamp = strtotime($serviceData->regdate);
        $oneMonthAgo = strtotime('-1 month');
        $isRefundable = $registrationTimestamp > $oneMonthAgo;


        if ($isServiceActivated){
            $deptId = 1; // Sales department
            $subject = urlencode("Refund Request - Service ID: #" . $serviceId);
            $message = urlencode("I am requesting a refund under the money-back guarantee.\n\nReason: \n\nI understand that my service will be terminated upon refund processing.");

            $primarySidebar->getChild('Service Details Actions')
                ->addChild('RefundRequest', array(
                    'label' => 'Request a Refund',
                    'uri' => $isRefundable ? "submitticket.php?step=2&deptid={$deptId}&subject={$subject}&message={$message}" : "",
                    'order' => 1000,
                    'icon' => 'fa-undo',
                    'attributes' => array(
                        'class' => $isRefundable ? "" : 'refund-disabled',
                    ),
                ));
        }
    }
});

add_hook('ClientAreaSecondarySidebar', 100, function ($secondarySidebar) {

    // Remove entire menus
    $menusToRemove = ['Client Contacts'];
    foreach ($menusToRemove as $menu) {
        if (!is_null($secondarySidebar->getChild($menu))) {
            $secondarySidebar->removeChild($menu);
        }
    }

    // Remove child menus
    // Remove children from the support menu
    $supportMenu = $secondarySidebar->getChild('Support');
    if (!is_null($supportMenu)) {
        $itemsToRemove = ['Announcements', 'Network Status', 'Knowledgebase', 'Downloads'];
        foreach ($itemsToRemove as $item) {
            if ($supportMenu->getChild($item)) {
                $supportMenu->removeChild($item);
            }
        }
    }
});

//Validate customfield 'How did you hear about us?'
add_hook('ClientDetailsValidation', 100, function($vars) {
    $errors = [];
    $fieldId = 6; // Replace with the actual ID of your custom field
    if ($vars['customfield'][$fieldId] === "-- Please choose --")
        $errors[] = 'Please select an option for "How did you hear about us?".';

    return $errors;
});