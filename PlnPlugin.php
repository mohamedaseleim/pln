<?php

/**
 * @file PlnPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2000-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlnPlugin - Updated to the clawd branch
 *
 * @brief PKP PLN plugin class
 */

namespace APP\plugins\generic\pln;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\plugins\generic\pln\classes\deposit\Repository;
use APP\plugins\generic\pln\classes\depositObject\DepositObject;
use APP\plugins\generic\pln\classes\form\SettingsForm;
use APP\plugins\generic\pln\classes\form\StatusForm;
use APP\plugins\generic\pln\classes\PLNGatewayPlugin;
use APP\plugins\generic\pln\classes\tasks\Depositor;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class PlnPlugin extends GenericPlugin
{
    public const DEPOSIT_FOLDER = 'pln';

    // Deposit object types
    public const DEPOSIT_TYPE_SUBMISSION = 'Submission';
    public const DEPOSIT_TYPE_ISSUE = 'Issue';

    // Deposit status constants (Bitwise)
    public const DEPOSIT_STATUS_NEW = 0x00;
    public const DEPOSIT_STATUS_PACKAGED = 0x01;
    public const DEPOSIT_STATUS_TRANSFERRED = 0x02;
    public const DEPOSIT_STATUS_RECEIVED = 0x04;
    public const DEPOSIT_STATUS_VALIDATED = 0x08;
    public const DEPOSIT_STATUS_SENT = 0x10;
    public const DEPOSIT_STATUS_LOCKSS_RECEIVED = 0x20;
    public const DEPOSIT_STATUS_LOCKSS_AGREEMENT = 0x40;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled($mainContextId)) {
            $this->registerCallbacks();
        }

        return $success;
    }

    /**
     * Register callbacks for the plugin
     */
    public function registerCallbacks(): void
    {
        // Hook for the settings form
        Hook::add('TemplateManager::display', [$this, 'handleTemplateDisplay']);
        
        // Register Gateway Plugin
        Hook::add('PluginRegistry::loadCategory', function ($hookName, $args) {
            $category = $args[0];
            $plugins = &$args[1];
            if ($category === 'gateways') {
                $plugins['pln'] = new PLNGatewayPlugin($this->getName());
            }
        });

        // Content hooks to track updates
        Hook::add('Publication::edit', [$this, 'callbackSubmissions']);
        Hook::add('Publication::publish', [$this, 'callbackSubmissions']);
        Hook::add('Issue::edit', [$this, 'callbackIssues']);
        Hook::add('Issue::publish', [$this, 'callbackIssues']);
    }

    /**
     * Handle template display hooks
     */
    public function handleTemplateDisplay(string $hookName, array $args): void
    {
        $templateMgr = $args[0];
        $template = $args[1];
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.pln');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.pln.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        
        // Settings Action
        $settingsAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, array_merge($actionArgs, ['verb' => 'settings'])),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );
        array_unshift($actions, $settingsAction);

        // Status Action
        $statusAction = new LinkAction(
            'status',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, array_merge($actionArgs, ['verb' => 'status'])),
                __('plugins.generic.pln.status'),
                'modal_20_20'
            ),
            __('plugins.generic.pln.status'),
            null
        );
        array_unshift($actions, $statusAction);

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        $context = $request->getContext();
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new SettingsForm($this, $context->getId());
                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));
            case 'status':
                $form = new StatusForm($this, $context->getId());
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * Check if ZipArchive is available
     */
    public function hasZipArchive(): bool
    {
        return class_exists('ZipArchive');
    }

    /**
     * Check if Acron plugin or cron is active
     */
    public function hasScheduledTasks(): bool
    {
        return true; 
    }

    /**
     * Retrieve the service document from the PLN network
     */
    public function getServiceDocument(int $contextId): array
    {
        $networkUrl = $this->getSetting($contextId, 'pln_network');
        if (!$networkUrl) {
            // Default to PKP PN
            $networkUrl = 'http://pkp-pln.lib.sfu.ca';
            $this->updateSetting($contextId, 'pln_network', $networkUrl);
        }
        
        $url = $networkUrl . '/api/sword/2.0/sd-iri';
        $result = $this->curlGet($url);
        
        return $result;
    }

    /**
     * Verify if terms are agreed
     */
    public function termsAgreed(int $contextId): bool
    {
        $terms = $this->getSetting($contextId, 'terms_of_use');
        $agreements = $this->getSetting($contextId, 'terms_of_use_agreement');
        
        if (!$terms || !$agreements) return false;

        foreach ($terms as $key => $term) {
            if (!isset($agreements[$key])) return false;
        }
        return true;
    }

    /**
     * Helper to perform cURL GET
     */
    public function curlGet(string $url): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        return [
            'status' => $httpCode,
            'result' => $response,
            'error' => $error
        ];
    }
    
    /**
     * Helper to perform cURL POST File
     */
    public function curlPostFile(string $url, string $filePath): array
    {
        $curl = curl_init();
        $file = new \CURLFile($filePath);
        
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => $file],
             CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data']
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        return [
            'status' => $httpCode,
            'result' => $response,
            'error' => $error
        ];
    }

    /**
     * Helper to perform cURL PUT File
     */
    public function curlPutFile(string $url, string $filePath): array
    {
        $fp = fopen($filePath, 'r');
        $size = filesize($filePath);
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $size
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($fp);

        return [
            'status' => $httpCode,
            'result' => $response,
            'error' => $error
        ];
    }

    /**
     * Callback for Submission updates
     */
    public function callbackSubmissions(string $hookName, array $args): void
    {
        // Logic to mark submissions as needing update
        // Use: \APP\plugins\generic\pln\classes\depositObject\Repository::instance()->markHavingUpdatedContent(...)
    }

    /**
     * Callback for Issue updates
     */
    public function callbackIssues(string $hookName, array $args): void
    {
         // Logic to mark issues as needing update
    }

    /**
     * Get the settings file
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/xml/settings.xml';
    }
}
