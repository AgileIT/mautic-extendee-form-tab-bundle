<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendeeFormTabBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Model\FormModel;
use Mautic\FormBundle\Model\SubmissionModel;
use MauticPlugin\MauticExtendeeFormTabBundle\Helper\FormTabHelper;
use MauticPlugin\MauticExtendeeFormTabBundle\Service\SaveSubmission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\RouterInterface;

/**
 * Class SubmissionController.
 */
class SubmissionController extends FormController
{

    private function getPostActionVars($returnUrl)
    {
        return [
            'returnUrl'       => $returnUrl,
            'contentTemplate' => 'MauticFormBundle:Result:index',
        ];
    }

    private function getRedirectUrl()
    {
        // /** @var RouterInterface $router */
        // $router = $this->get('router');
        // $router->getRouteCollection()        if (0 === strpos($router->getRoute(), 'mautic_email_action') && $this->request->get('objectAction') == 'view') {
        //
        // }
    }

    /**
     * Gives a preview of the form.
     *
     * @param     $formId
     * @param int $objectId
     *
     * @return Response
     *
     */
    public function editAction($formId, $objectId = 0)
    {
        $formId    = (empty($formId)) ? InputHelper::int($this->request->get('formId')) : $formId;
        $contactId = InputHelper::int($this->request->get('contactId'));

        /** @var SubmissionModel $submissionModel */
        $submissionModel = $this->getModel('form.submission');
        $objectId        = (empty($objectId)) ? InputHelper::int($this->request->get('objectId')) : $objectId;
        if ($objectId) {
            /** @var Submission $submission */
            $submission = $submissionModel->getEntity($objectId);
        }

        /** @var FormModel $model */
        $model    = $this->getModel('form.form');
        $form     = $model->getEntity($formId);
        $template = null;
        $router   = $this->get('router');

        $formResultUrl = $this->generateUrl('mautic_form_results', ['objectId' => $formId]);

        if ($form === null) {
            return $this->postActionRedirect(
                array_merge(
                    $this->getPostActionVars($formResultUrl),
                    [
                        'flashes' => [
                            [
                                'type' => 'error',
                                'msg'  => 'mautic.form.error.notfound',
                            ],
                        ],
                    ]
                )
            );
        }

        $html = $model->getContent($form, true, false);

        $action = $router->generate(
            'mautic_formtabsubmission_edit',
            [
                'formId'    => $form->getId(),
                'objectId'  => $objectId,
                'contactId' => $contactId,
            ]
        );

        $formView   = $this->get('form.factory')->create(
            'form_tab_submission',
            [],
            [
                'action' => $action,
            ]
        );
        $flashes    = [];
        $closeModal = false;
        $new        = false;
        if ($this->request->getMethod() == 'POST') {
            $post                 = $this->request->request->get('mauticform');
            $post['submissionId'] = $objectId;
            if (!$objectId) {
                $new = true;
            }

            $server = $this->request->server->all();
            $return = (isset($server['HTTP_REFERER'])) ? $server['HTTP_REFERER'] : false;

            if (!empty($return)) {
                //remove mauticError and mauticMessage from the referer so it doesn't get sent back
                $return = InputHelper::url($return, null, null, null, ['mauticError', 'mauticMessage'], true);
                $query  = (strpos($return, '?') === false) ? '?' : '&';
            }

            $valid = true;
            if (!$this->isFormCancelled($formView)) {
                /** @var SaveSubmission $saveSubmission */
                $saveSubmission = $this->get('mautic.extendee.form.tab.service.save_submission');
                $result         = $saveSubmission->saveSubmission(
                    $post,
                    $server,
                    $form,
                    $this->request,
                    true,
                    $this->getModel('lead.lead')->getEntity($contactId)
                );
                if (!$result instanceof Submission && !empty($result['errors'])) {
                } else {
                    $closeModal = true;

                }
            } else {
                return new JsonResponse(['closeModal' => 1]);;
            }
        }


        // hide fomr start/end and button, because we wanna use controller view
        //$html     = preg_replace('/action="([^"]+)/', 'action="'.$action, $html, 1);
        $html = preg_replace('/<form(.*)>/', '', $html, 1);
        $html = preg_replace('/<button type="submit"(.*)<\/button>/', '', $html, 1);
        $html = str_replace('</form>', '', $html);

        // prepopulate
        if (!empty($submission)) {
            $this->populateValuesWithLead($submission, $html);
            if (!$contactId) {
                $contactId = $submission->getLead()->getId();
            }
        }

        if ($closeModal) {
            /** @var FormTabHelper $formTabHelper */
            $formTabHelper = $this->get('mautic.extendee.form.tab.helper');
            $formTabHelper->setResultCache('');
                return $this->delegateView(
                    [
                        'passthroughVars' => [
                            'target'     => '#form-results-'.$form->getId(),
                            'newContent' => $formTabHelper->getFormWithResult($form, $contactId)['content'],
                            'closeModal' => 1,
                        ],
                    ]
                );
        }

        return $this->delegateView(
            [
                'viewParameters'  => [
                    'content' => $html,
                    'name'    => $form->getName(),
                    'form'    => $formView->createView(),
                ],
                'contentTemplate' => 'MauticExtendeeFormTabBundle::form.html.php',
            ]
        );
    }

    /**
     * @param $objectId
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function deleteAction($objectId)
    {
        $flashes = [];

        if ($this->request->getMethod() == 'POST') {
            $model = $this->getModel('form.submission');

            // Find the result
            $entity = $model->getEntity($objectId);

            if ($entity === null) {
                return $this->accessDenied();
            } elseif (!$this->get('mautic.security')->hasEntityAccess(
                'form:forms:editown',
                'form:forms:editother',
                $entity->getCreatedBy()
            )
            ) {
                return $this->accessDenied();
            } else {
                $id = $entity->getId();
                $form = $entity->getForm();
                $contactId = $entity->getLead()->getId();
                $model->deleteEntity($entity);

                $flashes[] = [
                    'type'    => 'notice',
                    'msg'     => 'mautic.core.notice.deleted',
                    'msgVars' => [
                        '%name%' => '#'.$id,
                    ],
                ];
            }
        } //else don't do anything
        $formTabHelper = $this->get('mautic.extendee.form.tab.helper');
        $formTabHelper->setResultCache('');
        return $this->delegateView(
            [
                'passthroughVars' => [
                    'target'     => '#form-results-'.$form->getId(),
                    'newContent' => $formTabHelper->getFormWithResult($form, $contactId)['content'],
                    'closeModal' => 1,
                ],
            ]
        );
    }

    /**
     * @param      $formHtml
     */
    public function populateValuesWithLead(Submission $submission, &$formHtml)
    {

        $form     = $submission->getForm();
        $formName = $form->generateFormName();

        $fields = $form->getFields();
        /** @var \Mautic\FormBundle\Entity\Field $f */
        foreach ($fields as $f) {
            if (!empty($submission->getResults()[$f->getAlias()])) {
                $value = $submission->getResults()[$f->getAlias()];
                $this->populateField($f, $value, $formName, $formHtml);
            }
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $formName
     * @param $formHtml
     */
    public function populateField($field, $value, $formName, &$formHtml)
    {
        $alias = $field->getAlias();

        switch ($field->getType()) {
            case 'text':
            case 'email':
            case 'hidden':
                if (preg_match(
                    '/<input(.*?)id="mauticform_input_'.$formName.'_'.$alias.'"(.*?)value="(.*?)"(.*?)\/>/i',
                    $formHtml,
                    $match
                )) {
                    $replace  = '<input'.$match[1].'id="mauticform_input_'.$formName.'_'.$alias.'"'.$match[2].'value="'.$this->sanitizeValue(
                            $value
                        ).'"'
                        .$match[4].'/>';
                    $formHtml = str_replace($match[0], $replace, $formHtml);
                }
                break;
            case 'textarea':
                if (preg_match(
                    '/<textarea(.*?)id="mauticform_input_'.$formName.'_'.$alias.'"(.*?)>(.*?)<\/textarea>/i',
                    $formHtml,
                    $match
                )) {
                    $replace  = '<textarea'.$match[1].'id="mauticform_input_'.$formName.'_'.$alias.'"'.$match[2].'>'.$this->sanitizeValue(
                            $value
                        ).'</textarea>';
                    $formHtml = str_replace($match[0], $replace, $formHtml);
                }
                break;
            case 'checkboxgrp':
                if (is_string($value) && strrpos($value, '|') > 0) {
                    $value = explode('|', $value);
                } elseif (!is_array($value)) {
                    $value = [$value];
                }

                foreach ($value as $val) {
                    $val = $this->sanitizeValue($val);
                    if (preg_match(
                        '/<input(.*?)id="mauticform_checkboxgrp_checkbox(.*?)"(.*?)value="'.$val.'"(.*?)\/>/i',
                        $formHtml,
                        $match
                    )) {
                        $replace  = '<input'.$match[1].'id="mauticform_checkboxgrp_checkbox'.$match[2].'"'.$match[3].'value="'.$val.'"'
                            .$match[4].' checked />';
                        $formHtml = str_replace($match[0], $replace, $formHtml);
                    }
                }
                break;
            case 'radiogrp':
                $value = $this->sanitizeValue($value);
                if (preg_match(
                    '/<input(.*?)id="mauticform_radiogrp_radio(.*?)"(.*?)value="'.$value.'"(.*?)\/>/i',
                    $formHtml,
                    $match
                )) {
                    $replace  = '<input'.$match[1].'id="mauticform_radiogrp_radio'.$match[2].'"'.$match[3].'value="'.$value.'"'.$match[4]
                        .' checked />';
                    $formHtml = str_replace($match[0], $replace, $formHtml);
                }
                break;
            case 'select':
            case 'country':
                $regex = '/<select\s*id="mauticform_input_'.$formName.'_'.$alias.'"(.*?)<\/select>/is';
                if (preg_match($regex, $formHtml, $match)) {
                    $origText = $match[0];
                    $replace  = str_replace(
                        '<option value="'.$this->sanitizeValue($value).'">',
                        '<option value="'.$this->sanitizeValue($value).'" selected="selected">',
                        $origText
                    );
                    $formHtml = str_replace($origText, $replace, $formHtml);
                }

                break;
        }
    }

    /**
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function submitAction()
    {
        if ($this->request->getMethod() !== 'POST') {
            return $this->accessDenied();
        }
        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'merge', 'objectId' => 1]);

        $formView = $this->get('form.factory')->create(
            'form_tab_submission',
            [],
            [
                'action' => $action,
            ]
        );
        if ($this->request->getMethod() == 'POST') {
            $valid = true;
            if ($this->isFormCancelled($formView)) {
                $viewParameters = [
                    'objectId'     => 1,
                    'objectAction' => 'view',
                ];

                return $this->postActionRedirect(
                    [
                        'returnUrl'       => $this->generateUrl('mautic_contact_action', $viewParameters),
                        'viewParameters'  => $viewParameters,
                        'contentTemplate' => 'MauticLeadBundle:Lead:view',
                        'passthroughVars' => [
                            'closeModal' => 1, //just in case in quick form
                        ],
                    ]
                );
            }
        }
        $form = null;
        $post = $this->request->request->get('mauticform');
        if ($this->request->get('submissionId', '')) {
            $post['submissionId'] = $this->request->get('submissionId', '');
        }
        $server = $this->request->server->all();
        $return = (isset($server['HTTP_REFERER'])) ? $server['HTTP_REFERER'] : false;

        if (!empty($return)) {
            //remove mauticError and mauticMessage from the referer so it doesn't get sent back
            $return = InputHelper::url($return, null, null, null, ['mauticError', 'mauticMessage'], true);
            $query  = (strpos($return, '?') === false) ? '?' : '&';
        }

        $translator = $this->get('translator');

        if (!isset($post['formId']) && isset($post['formid'])) {
            $post['formId'] = $post['formid'];
        } elseif (isset($post['formId']) && !isset($post['formid'])) {
            $post['formid'] = $post['formId'];
        }

        //check to ensure there is a formId
        if (!isset($post['formId'])) {
            $error = $translator->trans('mautic.form.submit.error.unavailable', [], 'flashes');
        } else {
            $formModel = $this->getModel('form.form');
            /** @var Form $form */
            $form = $formModel->getEntity($post['formId']);

            //check to see that the form was found
            if ($form === null) {
                $error = $translator->trans('mautic.form.submit.error.unavailable', [], 'flashes');
            } else {
                //get what to do immediately after successful post
                //check to ensure the form is published
                $status             = $form->getPublishStatus();
                $dateTemplateHelper = $this->get('mautic.helper.template.date');
                if ($status == 'pending') {
                    $error = $translator->trans(
                        'mautic.form.submit.error.pending',
                        [
                            '%date%' => $dateTemplateHelper->toFull($form->getPublishUp()),
                        ],
                        'flashes'
                    );
                } elseif ($status == 'expired') {
                    $error = $translator->trans(
                        'mautic.form.submit.error.expired',
                        [
                            '%date%' => $dateTemplateHelper->toFull($form->getPublishDown()),
                        ],
                        'flashes'
                    );
                } elseif ($status != 'published') {
                    $error = $translator->trans('mautic.form.submit.error.unavailable', [], 'flashes');
                } else {

                    // remove action
                    $actions = $form->getActions();;
                    foreach ($actions as $action) {
                        $form->removeAction($action);
                    }
                    // remove matching field
                    foreach ($form->getFields() as &$field) {
                        $field->setLeadField('');
                    }
                    /** @var SaveSubmission $saveSubmission */
                    $saveSubmission = $this->get('mautic.extendee.form.tab.service.save_submission');
                    $result         = $saveSubmission->saveSubmission($post, $server, $form, $this->request, true);
                    if (!empty($result['errors'])) {
                        $error = ($result['errors']) ?
                            $this->get('translator')->trans('mautic.form.submission.errors').'<br /><ol><li>'.
                            implode('</li><li>', $result['errors']).'</li></ol>' : false;
                    }
                }
            }

            /* $viewParameters = [
                 'objectId'     => 1,
                 'objectAction' => 'view',
             ];

             return $this->postActionRedirect(
                 [
                     'returnUrl'       => $this->generateUrl('mautic_contact_action', $viewParameters),
                     'viewParameters'  => $viewParameters,
                     'contentTemplate' => 'MauticLeadBundle:Lead:view',
                     'passthroughVars' => [
                         'activeLink'    => '#mautic_contact_index',
                         'mauticContent' => 'lead',
                         'closeModal'    => 1, //just in case in quick form
                     ],
                 ]
             );*/

            if (!empty($error)) {
                $data['errorMessage'] = implode('', $error);
            } else {
                $data['successMessage'] = $this->get('translator')->trans('mautic.core.success');
            }
            $response = json_encode($data);

            return $this->render('MauticFormBundle::messenger.html.php', ['response' => $response]);
        }
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public function sanitizeValue($value)
    {
        return str_replace(['"', '>', '<'], ['&quot;', '&gt;', '&lt;'], strip_tags(rawurldecode($value)));
    }


}