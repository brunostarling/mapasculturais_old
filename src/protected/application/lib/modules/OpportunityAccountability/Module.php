<?php

namespace OpportunityAccountability;

use OpportunityPhases\Module as PhasesModule;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Project;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;
use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\ChatMessage;
use MapasCulturais\Definitions\ChatThreadType;
use MapasCulturais\Entities\ChatThread;
use MapasCulturais\Entities\Notification;
use MapasCulturais\Entities\RegistrationEvaluation;

/**
 * @property Module $evaluationMethod
 */
class Module extends \MapasCulturais\Module
{
    public const CHAT_THREAD_TYPE = "accountability-field";
    /**
     * @var Module
     */
    protected $evaluationMethod;

    function _init()
    {
        $app = App::i();

        $this->evaluationMethod = new EvaluationMethod($this->_config);
        $this->evaluationMethod->module = $this;

        $registration_repository = $app->repo('Registration');

        // impede que a fase de prestação de contas seja considerada a última fase da oportunidade
        $app->hook('entity(Opportunity).getLastCreatedPhase:params', function(Opportunity $base_opportunity, &$params) {
            $params['isAccountabilityPhase'] = 'NULL()';
        });

        // retorna a inscrição da fase de prestação de contas
        $app->hook('entity(Registration).get(accountabilityPhase)', function(&$value) use ($registration_repository){
            $opportunity = $this->opportunity->parent ?: $this->opportunity;
            $accountability_phase = $opportunity->accountabilityPhase;

            $value = $registration_repository->findOneBy([
                'opportunity' => $accountability_phase,
                'number' => $this->number
             ]);
        });

        // retorna o projeto relacionado à inscricão
        $app->hook('entity(Registration).get(project)', function(&$value) {
            if (!$value) {
                $first_phase = $this->firstPhase;
                if ($first_phase && $first_phase->id != $this->id) {
                    $value = $first_phase->project;
                }
            }
        });

        // na publicação da última fase, cria os projetos
        $app->hook('entity(Opportunity).publishRegistration', function (Registration $registration) use($app) {
            if (! $this instanceof \MapasCulturais\Entities\ProjectOpportunity) {
                return;
            }

            if (!$this->isLastPhase) {
                return;
            }

            $project = new Project;
            $project->status = 0;
            $project->type = 0;
            $project->name = $registration->projectName ?: ' ';
            $project->parent = $app->repo('Project')->find($this->ownerEntity->id);
            $project->isAccountability = true;
            $project->owner = $registration->owner;

            $first_phase = $registration->firstPhase;

            $project->registration = $first_phase;
            $project->opportunity = $this->parent ?: $this;

            $project->save();

            $first_phase->project = $project;
            $first_phase->save(true);

            $app->applyHookBoundTo($this, $this->getHookPrefix() . '.createdAccountabilityProject', [$project]);

        });

        $app->hook('template(project.<<single|edit>>.tabs):end', function(){
            $project = $this->controller->requestedEntity;

            if ($project->isAccountability) {                
                if ($project->canUser('@control') || $project->canUser('evaluate') || $project->opportunity->canUser('@controll')) {
                    $this->part('accountability/project-tab');
                }
            }
        },1000);

        // cria permissão project.evaluate para o projeto de prestaçao de contas
        $app->hook('can(Project.evaluate)', function($user, &$result) {
            $registration = $this->registration->accountabilityPhase ?? null;

            $result = $registration && $registration->canUser('evaluate', $user);
        });

        $app->hook('template(project.<<single|edit>>.tabs-content):end', function(){
            $project = $this->controller->requestedEntity;

            if ($project->isAccountability) {
                if ($project->canUser('@control') || $project->canUser('evaluate') || $project->opportunity->canUser('@controll')) {
                    $this->part('accountability/project-tab-content');
                } 
            }
        },1000);

        // Adidiona o checkbox haverá última fase
        $app->hook('template(opportunity.edit.new-phase-form):end', function () use ($app) {
            $app->view->part('widget-opportunity-accountability', ['opportunity' => '']);
        });

        $self = $this;
        $app->hook('entity(Opportunity).insert:after', function () use ($app, $self) {

            $opportunityData = $app->controller('opportunity');

            if ($this->isLastPhase && isset($opportunityData->postData['hasAccountability']) && $opportunityData->postData['hasAccountability']) {
                
                $self->createAccountabilityPhase($this->parent);
            }
        });

        $app->hook('template(project.<<single|edit>>.header-content):before', function () {
            $project = $this->controller->requestedEntity;

            if ($project->isAccountability) {
                $this->part('accountability/project-opportunity', ['opportunity' => $project->opportunity]);
            }
        });

        // Adiciona aba de projetos nas oportunidades com prestação de contas após a publicação da última fase
        $app->hook('template(opportunity.single.tabs):end', function () use ($app) {

            $entity = $this->controller->requestedEntity;

            // accountabilityPhase existe apenas quando lastPhase existe
            if ($entity->accountabilityPhase && $entity->lastPhase->publishedRegistrations) {
                $this->part('singles/opportunity-projects--tab', ['entity' => $entity]);
            }
        });

        $app->hook('template(opportunity.single.tabs-content):end', function () use ($app) {
            $entity = $this->controller->requestedEntity;

            // accountabilityPhase existe apenas quando lastPhase existe
            if ($entity->accountabilityPhase && $entity->lastPhase->publishedRegistrations) {
                $this->part('singles/opportunity-projects', ['entity' => $entity]);
            }
        });

        /**
         * adiciona o formulário do parecerista
         */
        $app->hook('template(project.single.accountability-content):end', function() use($app) {
            $project = $this->controller->requestedEntity;
            if ($accountability = $project->registration->accountabilityPhase ?? null) {
                $evaluation = $app->repo('RegistrationEvaluation')->findOneBy(['registration' => $accountability]);

                $form_params = [
                    'opportunity' => $accountability->opportunity, 
                    'registraton' => $accountability,
                    'evaluation' => $evaluation,
                ];

                $this->jsObject['evaluation'] = $evaluation;

                $this->part('accountability--evaluation-form', $form_params) ;
            }
        });

        // adiciona controller angular ao formulário de avaliação
        $app->hook('template(project.single.accountability-content):begin', function () use($app) {
            $project = $this->controller->requestedEntity;
            if ($accountability = $project->registration->accountabilityPhase ?? null) {
                if ($evaluation = $app->repo('RegistrationEvaluation')->findOneBy(['registration' => $accountability])) {
                    $criteria = [
                        'objectType' => RegistrationEvaluation::class, 
                        'objectId' => $evaluation->id, 
                        'type' => self::CHAT_THREAD_TYPE
                    ];
                    $chat_threads = $app->repo('ChatThread')->findBy($criteria);

                    $chats_grouped = [];

                    foreach($chat_threads as $thread) {
                        $chats_grouped[$thread->identifier] = $thread;
                    }

                    $this->jsObject['accountabilityChatThreads'] = (object) $chats_grouped;
                }
            }
        },-10);

        // adiciona controles de abrir e fechar chat e campo para edição
        $app->hook('template(project.single.registration-field-item):begin', function () {
            $project = $this->controller->requestedEntity;
            if (($accountability = $project->registration->accountabilityPhase ?? null) && $project->canUser('evaluate')) {
                $project = $this->controller->requestedEntity;
                $this->part('accountability/registration-field-controls');
            }

        });

        $app->hook('template(project.single.registration-field-item):end', function () {
            echo '<div class="clearfix"></div>';
            $this->part('chat', ['thread_id' => 'getChatByField(field).id', 'closed' => '!isChatOpen(field)']);
        });

        /**
         * Hook dentro  do modal de eventos
         */
        $app->hook('template(project.single.event-modal-form):begin', function (){
            echo "<input type='hidden' name='projectId' value='{$this->controller->requestedEntity->id}'>";
        });
        
        /**
         * Substituição dos seguintes termos 
         * - avaliação por parecer
         * - avaliador por parecerista
         * - inscrição por prestação de contas
         */
        $replacements = [
            i::__('Nenhuma avaliação enviada') => i::__('Nenhum parecer técnico enviado'),
            i::__('Configuração da Avaliação') => i::__('Configuração do Parecer Técnico'),
            i::__('Comissão de Avaliação') => i::__('Comissão de Pareceristas'),
            i::__('Inscrição') => i::__('Prestacão de Contas'),
            i::__('inscrição') => i::__('prestacão de contas'),
            // inscritos deve conter somente a versão com o I maiúsculo para não quebrar o JS
            i::__('Inscritos') => i::__('Prestacoes de Contas'),
            i::__('Inscrições') => i::__('Prestações de Contas'),
            i::__('inscrições') => i::__('prestações de contas'),
            i::__('Avaliação') => i::__('Parecer Técnico'),
            i::__('avaliação') => i::__('parecer técnico'),
            i::__('Avaliações') => i::__('Pareceres'),
            i::__('avaliações') => i::__('pareceres'),
            i::__('Avaliador') => i::__('Parecerista'),
            i::__('avaliador') => i::__('parecerista'),
            i::__('Avaliadores') => i::__('Pareceristas'),
            i::__('avaliadores') => i::__('pareceristas'),
        ];

        $app->hook('view.partial(singles/opportunity-<<tabs|evaluations--admin--table|registrations--tables--manager|evaluations--committee>>):after', function($template, &$html) use($replacements) {
            $phase = $this->controller->requestedEntity;
            if ($phase->isAccountabilityPhase) {
                $html = str_replace(array_keys($replacements), array_values($replacements), $html);
            }
         });

         // substitui botões de importar inscrições da fase anterior
         $app->hook('view.partial(import-last-phase-button).params', function ($data, &$template) {
            $opportunity = $this->controller->requestedEntity;
            
            if ($opportunity->isAccountabilityPhase) {
                $template = "accountability/import-last-phase-button";
            }
         });

         // cria a avaliação se é um parecerista visitando pela primeira vez o projeto
         $app->hook('GET(project.single):before', function() use($app) {
            $project = $this->requestedEntity;

            if($project && $project->isAccountability && $project->canUser('evaluate')) {
                if($accountability = $project->registration->accountabilityPhase ?? null) {
                    $criteria = [
                        'registration' => $accountability
                    ];

                    if (!$app->repo('RegistrationEvaluation')->findOneBy($criteria)) {
                        $evaluation = new RegistrationEvaluation;
                        $evaluation->user = $app->user;
                        $evaluation->registration = $accountability;
                        $evaluation->save(true);
                    }
                }
            }
         });

         // redireciona a ficha de inscrição da fase de prestação de contas para o projeto relacionado
         $app->hook('GET(registration.view):before', function() use($app) {
            $registration = $this->requestedEntity;
            if ($registration->opportunity->isAccountabilityPhase) {
                if ($project = $registration->project) {
                    $app->redirect($project->singleUrl . '#/tab=accountability');
                }
            }
         });


        $app->hook("POST(chatThread.createAccountabilityField)", function () use ($app) {
            $this->requireAuthentication();
            $evaluation_id = $this->data["evaluation"];
            $evaluation = $app->repo("RegistrationEvaluation")->find($evaluation_id);
            if ($evaluation->ownerUser->id != $app->user->id) {
                $this->errorJson("The user {$app->user->id} is not authorized to create a chat thread in this context.");
            }
            if ($app->repo("ChatThread")->findOneBy([
                "objectId" => $evaluation->id,
                "objectType" => $evaluation->getClassName(),
                "identifier" => $this->data["identifier"],
                "type" => self::CHAT_THREAD_TYPE]) !== null) {
                    $this->errorJson("An entity with the same specification already exists.");
            }
            $description = sprintf(i::__("Prestação de contas número %s"), $evaluation->registration->number);
            $thread = new ChatThread($evaluation, $this->data["identifier"], self::CHAT_THREAD_TYPE, $description);
            $thread->save(true);
            $app->disableAccessControl();
            $thread->createAgentRelation($evaluation->registration->owner, "participant");
            $app->enableAccessControl();
            $this->json($thread);
         });

         $app->hook('entity(Registration).get(project)', function(&$value, $metadata_key) use($app) {
            
            if(!$value && $this->previousPhase) {
                $this->previousPhase->registerFieldsMetadata();
                
                $cache_id = "registration:{$this->number}:$metadata_key";
                if($app->cache->contains($cache_id)) {
                    $value = $app->cache->fetch($cache_id);
                } else {
                    $value = $this->previousPhase->$metadata_key;
                    $app->cache->save($cache_id, $value, DAY_IN_SECONDS);
                }
            }
        });
    }

    function register()
    {
        $app = App::i();
        $opportunity_repository = $app->repo('Opportunity');
        $registration_repository = $app->repo('Registration');
        $project_repository = $app->repo('Project');

        $this->registerProjectMetadata('isAccountability', [
            'label' => i::__('Indica que o projeto é vinculado à uma inscrição aprovada numa oportunidade'),
            'type' => 'boolean',
            'default' => false
        ]);

        $this->registerProjectMetadata('opportunity', [
            'label' => i::__('Oportunidade da prestação de contas vinculada a este projeto'),
            'type' => 'Opportunity',
            'serialize' => function (Opportunity $opportunity) {
                return $opportunity->id;
            },
            'unserialize' => function ($opportunity_id, $opportunity) use($opportunity_repository, $app) {

                if ($opportunity_id) {
                    return $opportunity_repository->find($opportunity_id);
                } else {
                    return null;
                }
            }
        ]);

        $this->registerProjectMetadata('registration', [
            'label' => i::__('Inscrição da oportunidade da prestação de contas vinculada a este projeto (primeira fase)'),
            'type' => 'Registration',
            'serialize' => function (Registration $registration) {
                return $registration->id;
            },
            'unserialize' => function ($registration_id) use($registration_repository) {
                if ($registration_id) {
                    return $registration_repository->find($registration_id);
                } else {
                    return null;
                }
            }
        ]);

        $this->registerRegistrationMetadata('project', [
            'label' => i::__('Projeto da prestação de contas vinculada a esta inscrição (primeira fase)'),
            'type' => 'Project',
            'serialize' => function (Project $project) {
                return $project->id;
            },
            'unserialize' => function ($project_id) use($project_repository) {
                if ($project_id) {
                    return $project_repository->find($project_id);
                } else {
                    return null;
                }
            }
        ]);

        $this->registerOpportunityMetadata('isAccountabilityPhase', [
            'label' => i::__('Indica se a oportunidade é uma fase de prestação de contas'),
            'type' => 'boolean',
            'default' => false
        ]);

        $this->registerOpportunityMetadata('accountabilityPhase', [
            'label' => i::__('Indica se a oportunidade é uma fase de prestação de contas'),
            'type' => 'Opportunity',
            'serialize' => function (Opportunity $opportunity) {
                return $opportunity->id;
            },
            'unserialize' => function ($opportunity_id, $opportunity) use($opportunity_repository) {
                if ($opportunity_id) {
                    return $opportunity_repository->find($opportunity_id);
                } else {
                    return null;
                }
            }
        ]);

        $thread_type_description = i::__('Conversação entre proponente e parecerista no campo da prestação de contas');
        $definition = new ChatThreadType(self::CHAT_THREAD_TYPE, $thread_type_description, function (ChatMessage $message) {
            $thread = $message->thread;
            $evaluation = $thread->ownerEntity;
            $registration = $evaluation->registration;
            $notification_content = '';
            $sender = '';
            $recipient = '';
            $notification = new Notification;
            if ($message->thread->checkUserRole($message->user, 'admin')) {
                // mensagem do parecerista
                $notification->user = $registration->owner->user;
                $notification_content = i::__("Nova mensagem do parecerista da prestação de contas número %s");
                $sender = 'admin';
                $recipient = 'participant';
            } else {
                // mensagem do usuário responsável pela prestação de contas
                $notification->user = $evaluation->user;
                $notification_content = i::__("Nova mensagem na prestação de contas número %s");
                $sender = 'participant';
                $recipient = 'admin';
            }
            $notification->message = sprintf($notification_content, "<a href=\"{$registration->singleUrl}\" >{$registration->number}</a>");
            $notification->save(true);
            $this->sendEmailForNotification($message, $notification, $sender, $recipient);
        });
        $app->registerChatThreadType($definition);

        $this->evaluationMethod->register();
    }

    // Migrar essa função para o módulo "Opportunity phase"
    function createAccountabilityPhase(Opportunity $parent)
    {

        $opportunity_class_name = $parent->getSpecializedClassName();

        $last_phase = \OpportunityPhases\Module::getLastCreatedPhase($parent);

        $phase = new $opportunity_class_name;

        $phase->status = Opportunity::STATUS_DRAFT;
        $phase->parent = $parent;
        $phase->ownerEntity = $parent->ownerEntity;

        $phase->name = i::__('Prestação de Contas');
        $phase->registrationCategories = $parent->registrationCategories;
        $phase->shortDescription = i::__('Descrição da Prestação de Contas');
        $phase->type = $parent->type;
        $phase->owner = $parent->owner;
        $phase->useRegistrations = true;
        $phase->isOpportunityPhase = true;
        $phase->isAccountabilityPhase = true;

        $_from = $last_phase->registrationTo ? clone $last_phase->registrationTo : new \DateTime;
        $_to = $last_phase->registrationTo ? clone $last_phase->registrationTo : new \DateTime;
        $_to->add(date_interval_create_from_date_string('1 days'));

        $phase->registrationFrom = $_from;
        $phase->registrationTo = $_to;

        $phase->save(true);
    }
}
