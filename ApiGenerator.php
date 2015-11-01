<?php

namespace machour\yii2\swagger\api;


use ReflectionClass;
use Yii;
use yii\base\Configurable;
use yii\base\Exception;
use yii\web\Response;

class ApiGenerator implements Configurable
{
    /**
     * @var string The models namespace. Must be defined by the daughter class
     */
    public $modelsNamespace;

    /**
     * Swagger current version
     * @var string
     */
    public $swaggerVersion;

    /**
     * Swagger documentation action
     * @var string
     */
    public $swaggerDocumentationAction;

    /**
     * Swagger security definitions
     * @var array
     */
    public $securityDefinitions;

    /**
     * @var
     */
    public $controller;

    /**
     * @var array List of supported HTTP methods
     */
    public $supportedMethods;

    /**
     * Holds all definitions
     *
     * @var array
     */
    private $definitions = [];

    public function __construct($config)
    {
        if (!empty($config)) {
            Yii::configure($this, $config);
        }
    }

    /**
     * Checks if the given $type is an ApiModel
     *
     * @param $type
     * @return bool Returns TRUE if it's a model class, FALSE otherwise
     */
    private function isDefinedType($type)
    {
        return in_array($type, $this->definitions);
    }

    /**
     * Generates the swagger configuration file
     *
     * This method will inspect the current API controller and generate the
     * configuration based on the doc blocks found.
     *
     * @return array The definition as an array
     */
    public function getJson()
    {
        $ret = [
            'swagger' => $this->swaggerVersion,
            'info' => [],
            'host' => '',
            'basePath' => '',
            'tags' => [],
            'schemes' => [],
            'paths' => [],
            'securityDefinitions' => $this->securityDefinitions,
            'definitions' => [],
        ];

        $class = new ReflectionClass($this->controller);

        $classDoc = $class->getDocComment();
        $tags = ApiDocParser::parseDocCommentTags($classDoc);

        $this->definitions[] = 'ApiResponse';
        $ret['definitions']['ApiResponse'] = $this->parseModel('machour\\yii2\\swagger\\api\\ApiResponse', false);
        if (isset($tags['definition'])) {
            $this->definitions = array_merge($this->definitions, $tags['definition']);
            $ret['definitions'] = array_merge($ret['definitions'], $this->parseModels($this->mixedToArray($tags['definition'])));
        }

        $ret['info']['description'] = ApiDocParser::parseDocCommentDetail($classDoc);

        if (isset($tags['version'])) {
            $ret['info']['version'] = $tags['version'];
        }

        $ret['info']['title'] = ApiDocParser::parseDocCommentSummary($classDoc);

        if (isset($tags['termsOfService'])) {
            $ret['info']['termsOfService'] = $tags['termsOfService'];
        }

        if (isset($tags['email'])) {
            $ret['info']['contact'] = [
                'email' => $tags['email'],
            ];
        }

        if (isset($tags['license'])) {
            $license = $this->tokenize($tags['license'], 2);
            $ret['info']['license'] = [
                'name' => $license[1],
                'url' => $license[0],
            ];
        }

        $tagsExternalDocs = [];
        if (isset($tags['tagExternalDocs'])) {
            $tagExternalDocs = $this->mixedToArray($tags['tagExternalDocs']);
            foreach ($tagExternalDocs as $externalDocs) {
                list($tag, $url, $description) = $this->tokenize($externalDocs, 3);
                $tagsExternalDocs[$tag] = [
                    'description' => $description,
                    'url' => $url,
                ];
            }
        }

        if (isset($tags['tag'])) {
            foreach ($this->mixedToArray($tags['tag']) as $tag) {
                $tagDef = array_combine(['name', 'description'], $this->tokenize($tag, 2));
                if (isset($tagsExternalDocs[$tagDef['name']])) {
                    $tagDef['externalDocs'] = $tagsExternalDocs[$tagDef['name']];
                }
                $ret['tags'][] = $tagDef;

            }
        }

        $ret['basePath'] = isset($tags['basePath']) ? $tags['basePath'] : Url::to('/');
        $ret['host'] = isset($tags['host']) ? $tags['host'] : Url::to('/', 1);

        if (isset($tags['scheme'])) {
            if (is_array($tags['scheme'])) {
                foreach ($tags['scheme'] as $scheme) {
                    $ret['schemes'][] = $scheme;
                }
            } else {
                $ret['schemes'][] = $tags['scheme'];
            }
        }

        $produces = [];
        if (isset($tags['produces'])) {
            $produces = $this->mixedToArray($tags['produces']);
        }

        $ret['paths'] = $this->parseMethods($class, $produces);

        if (isset($tags['externalDocs'])) {
            $externalDocs = $this->tokenize($tags['externalDocs'], 2);
            $ret['externalDocs'] = [
                'description' => $externalDocs[1],
                'url' => $externalDocs[0],
            ];
        }

        Yii::$app->response->format = Response::FORMAT_JSON;
        return $ret;
    }

    /**
     * Returns the models definition
     *
     * @param array $definitions
     * @return array
     * @throws Exception
     */
    private function parseModels($definitions)
    {
        $ret = [];
        foreach ($definitions as $definition)
        {
            $ret[$definition] = $this->parseModel($definition);
        }
        return $ret;
    }

    /**
     * Returns a model definition
     *
     * @param $definition
     * @param bool $xml
     * @return array
     * @throws Exception
     */
    private function parseModel($definition, $xml = true) {
        $model = strpos($definition, '\\') === false ?
            $this->modelsNamespace . '\\' . $definition :
            $definition;
        if (!is_subclass_of($model, ApiModel::class)) {
            throw new Exception("The model definition for $model was not found", 501);
        }

        $ret = [
            'type' => 'object',
        ];

        $class = new ReflectionClass($model);

        $properties =  $this->parseProperties($class);
        foreach ($properties as $name => &$property) {
            if (isset($property['required'])) {
                unset($property['required']);
                if (!isset($ret['required'])) {
                    $ret['required'] = [];
                }
                $ret['required'][] = $name;
            }
        }
        $ret['properties'] = $properties;

        if ($xml) {
            $ret['xml'] = ['name' => $definition];
        }

        return $ret;
    }

    /**
     * Returns the class properties
     *
     * Used for models
     *
     * @param ReflectionClass $class
     * @return array
     */
    private function parseProperties($class)
    {
        $ret = [];

        $defaults = $class->getDefaultProperties();

        foreach ($class->getProperties() as $property) {
            $tags = ApiDocParser::parseDocCommentTags($property->getDocComment());
            list($type, $description) = $this->tokenize($tags['var'], 2);

            $p = [];
            if (strpos($type, '[]') > 0) {
                $type = str_replace('[]', '', $type);
                $p['type'] = 'array';
                $p['xml'] = ['name' => preg_replace('!s$!', '', $property->name), 'wrapped' => true];
                if ($this->isDefinedType($type)) {
                    $p['items'] = ['$ref' => $this->getDefinition($type)];
                } else {
                    $p['items'] = ['type' => $type];
                }
            } elseif ($this->isDefinedType($type)) {
                $p['$ref'] = $this->getDefinition($type);
            } else {
                $p['type'] = $type;
                $enums = isset($tags['enum']) ? $this->mixedToArray($tags['enum']) : [];
                foreach ($enums as $enum) {
                    $p['enum'] = $this->tokenize($enum);
                }
            }

            if (isset($tags['format'])) {
                $p['format'] = $tags['format'];
            }

            if (isset($tags['example'])) {
                $p['example'] = $tags['example'];
            }

            if (isset($tags['required'])) {
                $p['required'] = true;
            }

            if (!empty($description)) {
                $p['description'] = $description;
            }

            if (!is_null($defaults[$property->name])) {
                $p['default'] = $defaults[$property->name];
            }

            $ret[$property->name] = $p;
        }
        return $ret;
    }

    /**
     * Returns the methods definition
     *
     * @param ReflectionClass $class
     * @param array $produces The class level @produces tags
     * @return array
     * @throws Exception
     */
    private function parseMethods($class, $produces)
    {
        $ret = [];
        foreach ($class->getMethods() as $method) {
            $def = [];

            $methodDoc = $method->getDocComment();

            if ($this->controller !== $method->class) {
                continue;
            }

            $tags = ApiDocParser::parseDocCommentTags($methodDoc);

            if (!isset($tags['path'])) {
                continue;
            }

            if (!in_array($tags['method'], $this->supportedMethods)) {
                throw new Exception("Unknown HTTP method specified in {$method->name} : {$tags['method']}", 501);
            }

            $enums = isset($tags['enum']) ? $this->mixedToArray($tags['enum']) : [];
            $availableEnums = [];
            foreach ($enums as $enum) {
                $data = $this->tokenize($enum);
                $availableEnums[str_replace('$', '', array_shift($data))] = $data;
            }

            if (isset($tags['tag'])) {
                $def['tags'] = $this->mixedToArray($tags['tag']);
            }

            $def['summary'] = ApiDocParser::parseDocCommentSummary($methodDoc);
            $def['description'] = ApiDocParser::parseDocCommentDetail($methodDoc);

            $def['operationId'] = $this->getOperationId($method);

            if (isset($tags['consumes'])) {
                $def['consumes'] = $this->mixedToArray($tags['consumes']);
            }

            if (isset($tags['produces'])) {
                $def['produces'] = $this->mixedToArray($tags['produces']);
            } elseif (!empty($produces)) {
                $def['produces'] = $produces;
            }

            $def['parameters'] = $this->parseParameters($method, $tags, $availableEnums);

            $def['responses'] = [];

            if (isset($tags['default'])) {
                $def['responses']['default'] = [
                    'description' => $tags['default']
                ];
            }

            if (isset($tags['return'])) {
                list($type, $description) = $this->tokenize($tags['return'], 2);
                $def['responses'][200] = [];
                if (!empty($description)) {
                    $def['responses'][200]['description'] = $description;
                }

                $schema = [];
                if (strpos($type, '[]') > 0) {
                    $schema['type'] = 'array';
                    $type = str_replace('[]', '', $type);
                    if ($this->isDefinedType($type)) {
                        $schema['items'] = ['$ref' => $this->getDefinition($type)];
                    }
                } elseif ($this->isDefinedType($type)) {
                    $schema = ['$ref' => $this->getDefinition($type)];
                } elseif (preg_match('!^Map\((.*)\)$!', $type, $matches)) {
                    // Swaggers Map Primitive
                    $schema['type'] = 'object';
                    list($type, $format) = $this->getTypeAndFormat($matches[1]);
                    $schema['additionalProperties'] = ['type' => $type];
                    if (!is_null($format)) {
                        $schema['additionalProperties']['format'] = $format;
                    }
                } else {
                    $schema['type'] = $type;
                }
                $def['responses'][200]['schema'] = $schema;
                if (isset($tags['emitsHeader'])) {
                    $def['responses'][200]['headers'] = [];
                    $headers = $this->mixedToArray($tags['emitsHeader']);
                    foreach ($headers as $header) {
                        list($type, $name, $description) = $this->tokenize($header, 3);
                        list($type, $format) = $this->getTypeAndFormat($type);
                        $def['responses'][200]['headers'][$name] = [
                            'type' => $type,
                            'format' => $format,
                            'description' => $description
                        ];
                    }
                }
            }

            if (isset($tags['errors'])) {
                $errors = $this->mixedToArray($tags['errors']);
                foreach ($errors as $error) {
                    if (preg_match('!(\d+)\s+(.*)$!', $error, $matches)) {
                        $def['responses'][$matches[1]] = ['description' => $matches[2]];
                    }
                }
            }

            if (isset($tags['security'])) {
                $security = [];
                $secs = $this->mixedToArray($tags['security']);
                foreach ($secs as $sec) {
                    list($bag, $permission) = $this->tokenize($sec, 2);
                    if (!isset($security[$bag])) {
                        $security[$bag] = [];
                    }
                    if (!empty($permission)) {
                        $security[$bag][] = $permission;
                    }
                }
                foreach ($security as $section => $privileges) {
                    $def['security'][] = [$section => $privileges];
                }
            }

            if (!isset($ret[$tags['path']])) {
                $ret[$tags['path']] = [];
            }
            $ret[$tags['path']][$tags['method']] = $def;
        }
        return $ret;
    }

    /**
     * Gets the swagger operation id from a method
     *
     * @param ReflectionMethod $method
     * @return string
     */
    private function getOperationId($method)
    {
        return strtolower(substr($method->name, 6, 1)) . substr($method->name, 7);
    }

    /**
     * Tells if the given method have the given parameter in its signature
     *
     * @param ReflectionMethod $method The method to inspect
     * @param string $parameter The parameter name
     * @return bool Returns TRUE if the parameter is present in the signature, FALSE otherwise
     */
    private function haveParameter($method, $parameter)
    {
        foreach ($method->getParameters() as $param) {
            if ($param->name == $parameter) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the parameters definition
     *
     * @param ReflectionMethod $method
     * @param array $tags
     * @param array $availableEnums
     * @return array
     */
    private function parseParameters($method, $tags, $availableEnums)
    {
        $ret = [];
        $constraints = [];
        if (isset($tags['constraint'])) {
            foreach ($this->mixedToArray($tags['constraint']) as $constraint) {
                list($type, $parameter, $value) = $this->tokenize($constraint, 3);
                $parameter = ltrim($parameter, '$');
                if (!isset($constraints[$parameter])) {
                    $constraints[$parameter] = [];
                }
                $constraints[$parameter][$type] = $value;
            }
        }
        foreach (['parameter' => true, 'optparameter' => false] as $tag => $required) {
            if (isset($tags[$tag])) {
                $parameters = $this->mixedToArray($tags[$tag]);
                foreach ($parameters as $parameter) {
                    list($type, $name, $description) = $this->tokenize($parameter, 3);
                    $name = ltrim($name, '$');
                    $p = [];
                    $p['name'] = $name;

                    $http_method = $tags['method'];

                    $consumes = isset($tags['consumes']) ? $tags['consumes'] : '';
                    switch ($consumes) {
                        case 'multipart/form-data':
                            $p['in'] = $this->haveParameter($method, $name) ? 'path' : 'formData';
                            break;

                        case 'application/x-www-form-urlencoded':
                            $p['in'] = $this->haveParameter($method, $name) ? 'path' : 'formData';
                            break;

                        default:
                            if (in_array($http_method, ['put', 'post'])) {
                                if ($this->isPathParameter($name, $tags['path'])) {
                                    $p['in'] = 'path';
                                } else {
                                    $p['in'] = 'body';
                                }
                            } else {
                                if ($this->isPathParameter($name, $tags['path'])) {
                                    $p['in'] = 'path';
                                } else {
                                    $p['in'] = $this->haveParameter($method, $name) ? 'query' : 'header';
                                }
                            }
                            break;
                    }
                    if (isset($constraints[$name])) {
                        foreach ($constraints[$name] as $constraint => $value) {
                            $p[$constraint] = $value;
                        }
                    }
                    if (!empty($description)) {
                        $p['description'] = $description;
                    }
                    $p['required'] = $required;
                    if (strpos($type, '[]') > 0) {
                        $type = str_replace('[]', '', $type);
                        if ($this->isDefinedType($type)) {
                            $p['schema'] = [
                                'type' => 'array',
                                'items' => [
                                    '$ref' => $this->getDefinition($type),
                                ]
                            ];
                        } else {
                            $p['type'] = 'array';
                            $p['items'] = ['type' => $type];
                            if (isset($availableEnums[$name])) {
                                $p['items']['enum'] = $availableEnums[$name];
                                $p['items']['default'] = count($availableEnums[$name]) ? $availableEnums[$name][0] : '';
                            }
                            $p['collectionFormat'] = 'csv';
                        }
                    } elseif ($this->isDefinedType($type)) {
                        $p['schema'] = ['$ref' => $this->getDefinition($type)];
                    } else {
                        list($type, $format) = $this->getTypeAndFormat($type);
                        $p['type'] = $type;
                        if ($format !== null) {
                            $p['format'] = $format;
                        }
                    }
                    $ret[] = $p;
                }
            }
        }
        return $ret;
    }

    /**
     * Gets the type and format of a parameter
     *
     * @param $type
     * @return array An array with the type as first element, and the format
     *               or null as second element
     */
    private function getTypeAndFormat($type)
    {
        switch ($type) {
            case 'int64':
            case 'int32': return ['integer', $type];
            case 'date-time': return ['string', $type];
            default: return [$type, null];
        }
    }

    /**
     * Tells if a parameter is part of the swagger path
     *
     * @param string $name The parameter name
     * @param string $path The swagger path
     * @return bool Returns TRUE if the parameter is in the path, FALSE otherwise
     */
    private function isPathParameter($name, $path)
    {
        return strpos($path, '{' . $name . '}') !== false;
    }

    /**
     * Returns the definition path for a type
     *
     * @param string $type The model name
     * @return string
     */
    private function getDefinition($type)
    {
        return '#/definitions/' . $type;
    }

    /**
     * Converts a mixed (array or string) value to an array
     *
     * @param string|array $mixed The mixed value
     * @return array The resulting array
     */
    private function mixedToArray($mixed)
    {
        return is_array($mixed) ? array_values($mixed) : [$mixed];
    }

    /**
     * Tokenize an input string
     *
     * @param string $input The string to be tokenized
     * @param int $limit The number of tokens to generate
     * @return array
     */
    private function tokenize($input, $limit = -1)
    {
        $chunks = preg_split('!\s+!', $input, $limit);
        if ($limit !== -1 && count($chunks) != $limit) {
            $chunks += array_fill(count($chunks), $limit, '');
        }
        return $chunks;
    }

}