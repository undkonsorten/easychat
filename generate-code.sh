#!/bin/sh
#UNcomment for debugging
#set -x
set -e

docker pull openapitools/openapi-generator-cli

#Generates everything
#Mustaches can be found here https://github.com/OpenAPITools/openapi-generator/tree/master/modules/openapi-generator/src/main/resources/php-symfony
rm -rf Classes/Domain/Model/Gen
docker run --rm --user $(id -u):$(id -g) -v ${PWD}:/local openapitools/openapi-generator-cli generate \
    -i /local/Schema/openai.models.yml \
    -g php-symfony \
    -o /local/.generated/Classes \
    --model-package Domain\\Model\\Gen \
    --invoker-package Undkonsorten\\Easychat \
    --additional-properties=hideGenerationTimestamp=true,srcBasePath=/src,variableNamingConvention=camelCase,composerProjectName=Easychat,composerVendorName=Undkonsorten \
    --global-property=models,modelDocs=false,modelTests=false

mv .generated/Classes/src/Domain/Model/Gen Classes/Domain/Model
rm -rf .generated
echo "done"
