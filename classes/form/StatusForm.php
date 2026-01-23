<?php

namespace APP\plugins\generic\pln\classes\form;

use APP\plugins\generic\pln\PlnPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;

class StatusForm extends Form
{
    /**
     * Constructor
     */
    public function __construct(private PlnPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('status.tpl'));
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $context = $request->getContext();
        $networkStatus = $this->plugin->getSetting($context->getId(), 'pln_accepting');
        $networkStatusMessage = $this->plugin->getSetting($context->getId(), 'pln_accepting_message')
            ?: __($networkStatus ? 'plugins.generic.pln.notifications.pln_accepting' : 'plugins.generic.pln.notifications.pln_not_accepting');

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'networkStatus' => $networkStatus,
            'networkStatusMessage' => $networkStatusMessage
        ]);

        return parent::fetch($request, $template, $display);
    }
}
