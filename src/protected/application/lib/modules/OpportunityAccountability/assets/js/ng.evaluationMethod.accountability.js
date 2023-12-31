(function (angular) {
    "use strict";
    var module = angular.module('ng.evaluationMethod.accountability', []);
    

    module.config(['$httpProvider', function ($httpProvider) {
        $httpProvider.defaults.headers.post['Content-Type'] = 'application/x-www-form-urlencoded;charset=utf-8';
        $httpProvider.defaults.headers.common["X-Requested-With"] = 'XMLHttpRequest';
        $httpProvider.defaults.transformRequest = function (data) {
            var result = angular.isObject(data) && String(data) !== '[object File]' ? $.param(data) : data;

            return result;
        };
    }]);


    module.factory('ApplyAccountabilityEvaluationService', ['$http', '$rootScope', 'UrlService', function ($http, $rootScope, UrlService) {
        
        return {
            apply: function (from, to, status) {
                var data = {from: from, to: to, status};
                var url = MapasCulturais.createUrl('opportunity', 'applyEvaluationsAccountability', [MapasCulturais.entity.id]);
                
                return $http.post(url, data).
                    success(function (data, status) {
                        $rootScope.$emit('registration.create', {message: "Opportunity registration was created", data: data, status: status});
                    }).
                    error(function (data, status) {
                        $rootScope.$emit('error', {message: "Cannot create opportunity registration", data: data, status: status});
                    });
            },
        };
    }]);

    module.controller('ApplyAccountabilityEvaluationResults',['$scope', 'RegistrationService', 'ApplyAccountabilityEvaluationService', 'EditBox', function($scope, RegistrationService, ApplyAccountabilityEvaluationService, EditBox){
        
        var evaluation = MapasCulturais.evaluation;
        var statuses = RegistrationService.registrationStatusesNames.filter((status) => {
            if(status.value > 1) return status;
        });
        $scope.data = {
            registration: evaluation ? evaluation.evaluationData.status : null,
            obs: evaluation ? evaluation.evaluationData.obs : null,
            registrationStatusesNames: statuses,
            applying: false,
            status: 'pending'
        };

        $scope.getStatusLabel = (status) => {
            for(var i in statuses){
                if(statuses[i].value == status){
                    return statuses[i].label;
                }
            }
            return '';
        };

        $scope.applyEvaluations = () => {
            if(!$scope.data.applyFrom || !$scope.data.applyTo) {
                // @todo: utilizar texto localizado
                MapasCulturais.Messages.error("É necessário selecionar os campos Avaliação e Status");
                return;
            }

            $scope.data.applying = true;
            ApplyAccountabilityEvaluationService.apply($scope.data.applyFrom, $scope.data.applyTo, $scope.data.status).
                success(() => {
                    $scope.data.applying = false;
                    MapasCulturais.Messages.success('Avaliações aplicadas com sucesso');
                    EditBox.close('apply-consolidated-results-editbox');
                    $scope.data.applyFrom = null;
                    $scope.data.applyTo = null;
                }).
                error((data, status) => {
                    $scope.data.applying = false;
                    $scope.data.errorMessage = data.data;
                    MapasCulturais.Messages.success('As avaliações não foram aplicadas.');
                })
        }
    }]);

    module.factory('AccountabilityEvaluationService', ['$http', '$rootScope', 'UrlService', function ($http, $rootScope, UrlService) {
        
        return {
            save: function (registrationId, evaluationData, uid) {
                var url = MapasCulturais.createUrl('registration', 'saveEvaluation', [registrationId]);
                return $http.post(url, {data: evaluationData, uid});
            },

            send: function (registrationId, evaluationData, uid) {
                var url = MapasCulturais.createUrl('registration', 'saveEvaluation', {id: registrationId, status: 'evaluated'});
                return $http.post(url, {data: evaluationData, uid});
            },

            createChat: function (evaluation, identifier) {
                var url = MapasCulturais.createUrl('chatThread', 'createAccountabilityField');
                return $http.post(url, {evaluation, identifier});
            },

            closeChat: function (chat) {
                var url = MapasCulturais.createUrl('chatThread', 'close', [chat.id]);
                return $http.post(url);
            },

            openChat: function (chat) {
                var url = MapasCulturais.createUrl('chatThread', 'open', [chat.id]);
                return $http.post(url);
            }
        };
    }]);

    module.controller('AccountabilityEvaluationForm', ['$scope', '$rootScope', 'AccountabilityEvaluationService', function($scope, $rootScope, AccountabilityEvaluationService) {
        if(!MapasCulturais.evaluation) {
            return;
        }
        const evaluationId = MapasCulturais.evaluation.id;
        const registrationId = MapasCulturais.evaluation.registration.id;

        $scope.chatThreads = MapasCulturais.accountabilityChatThreads;
        $scope.openChats = {};

        $scope.evaluationData = MapasCulturais.evaluation.evaluationData;
        
        $rootScope.closedChats = $rootScope.closedChats || {};
        
        Object.keys($scope.chatThreads).forEach(function(identifier) {
            const chat = $scope.chatThreads[identifier];

            $scope.openChats[identifier] = chat.status == 1;
            if(chat.status != 1) {
                $rootScope.closedChats[chat.id] = true;
            }
        });
        
        if (!$scope.evaluationData.openFields) {
            $scope.evaluationData.openFields = {};
        }

        $scope.getFieldIdentifier = function(field) {
            return field.fieldName || field.group;
        }

        $scope.getChatByField = function (field) {
            let identifier = $scope.getFieldIdentifier(field);
            return this.chatThreads[identifier];
        }

        $scope.isChatOpen = function(field) {
            let chat = this.getChatByField(field)
            return !! chat && chat.status == 1;
        };

        $scope.chatExists = function(field) {
            return this.getChatByField(field) != undefined;
        };

        $scope.toggleChat = function(field) {
            const identifier = $scope.getFieldIdentifier(field);
            if (!this.chatExists(field)) {
                AccountabilityEvaluationService.createChat(evaluationId, identifier).then(function(response) {
                    const newChatThread = response.data;
                    $scope.chatThreads[newChatThread.identifier] = newChatThread;
                });
            } else {
                const chat = this.getChatByField(field);
                if (this.isChatOpen(field)) {
                    AccountabilityEvaluationService.closeChat(chat).then(function(response) {
                        const chat = response.data;
                        $scope.chatThreads[chat.identifier] = chat;
                        $rootScope.closedChats[chat.id] = true;
                    });
                } else {
                    AccountabilityEvaluationService.openChat(chat).then(function(response) {
                        const chat = response.data;
                        $scope.chatThreads[chat.identifier] = chat;
                        delete $rootScope.closedChats[chat.id];
                    });
                }
            }
        };

        var lastSentDataJSON = JSON.stringify($scope.evaluationData);
        $scope.$watch('evaluationData',function(ov, nv) {
            clearInterval($scope.changeTimeout);

            $scope.changeTimeout = setTimeout(function() {
                if(lastSentDataJSON == JSON.stringify($scope.evaluationData)){
                    return;
                }
                lastSentDataJSON = JSON.stringify($scope.evaluationData);
                AccountabilityEvaluationService.save(registrationId, $scope.evaluationData, MapasCulturais.evaluation.user).success(function() {
                    MapasCulturais.Messages.success('Salvo');
                });
            }, 2000);
        }, true);


        $scope.sendEvaluation = function () {
            if(!confirm("Você tem certeza que deseja finalizer o parecer técnico? \n\nApós a finalização não será mais possível modificar o parecer.")){
                return;
            }

            AccountabilityEvaluationService.send(registrationId, $scope.evaluationData, MapasCulturais.evaluation.user).success(function() {
                MapasCulturais.Messages.success('Salvo');
            });
        }
    }]);


})(angular);