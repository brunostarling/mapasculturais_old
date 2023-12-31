<?php
use MapasCulturais\i;

$template_hook_params = ['registration' => $registration, 'opportunity' => $opportunity];
 
// $this->jsObject['evaluationConfiguration'] = $entity->evaluationMethodConfiguration;
?>
<?php $this->applyTemplateHook('evaluationForm.accountability', 'before', $template_hook_params) ?>
<div class="registration-fieldset accountability-evaluation-form">
    <?php $this->applyTemplateHook('evaluationForm.accountability', 'begin', $template_hook_params) ?>
    <section>
        <h4> <?php i::_e('Resultado') ?> </h4>
        <label>
            <input type="radio" ng-model="evaluationData.result" value="10">
            <?php i::_e('Aprovado') ?>
        </label>

        <label>
            <input type="radio" ng-model="evaluationData.result" value="8">
            <?php i::_e('Aprovado com ressalvas') ?>
        </label>

        <label>
            <input type="radio" ng-model="evaluationData.result" value="3">
            <?php i::_e('Não aprovado') ?>
        </label>
    </section>

    <section>
        <h4><?= i::__('Parecer técnico') ?></h4>
        <textarea ng-model="evaluationData.obs" class="auto-height"></textarea>
    </section>

    <section class="actions">
        <button class="btn btn-primary align-right" ng-click="sendEvaluation()"><?php i::_e('Finalizar e enviar o parecer técnico') ?></button>
    </section>
    <?php $this->applyTemplateHook('evaluationForm.accountability', 'end', $template_hook_params) ?>
</div>
<?php $this->applyTemplateHook('evaluationForm.accountability', 'after', $template_hook_params) ?>
