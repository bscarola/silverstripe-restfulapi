<?php
/**
 * Default RESTfulAPI Query handler
 * handles models request etc...
 * 
 * @author  Thierry Francois @colymba thierry@colymba.com
 * @copyright Copyright (c) 2013, Thierry Francois
 * 
 * @license http://opensource.org/licenses/BSD-3-Clause BSD Simplified
 * 
 * @package RESTfulAPI
 * @subpackage QueryHandler
 */
class RESTfulAPI_DefaultQueryHandler implements RESTfulAPI_QueryHandler
{

  /**
   * Current deSerializer instance
   * 
   * @var RESTfulAPI_DeSerializer
   */
  public $deSerializer;


  /**
   * Injector dependencies
   * Override in configuration to use your custom classes
   * 
   * @var array
   */
  private static $dependencies = array(
    'deSerializer' => '%$RESTfulAPI_BasicDeSerializer'
  );


	/**
   * Embedded records setting
   * Specify which relation ($has_one, $has_many, $many_many) model data should be embedded into the response
   *
   * Map of classes to embed for specific record
   * 'RequestedClass' => array('ClassToEmbed', 'Another')
   *
   * Non embedded response:
   * {
   *   'member': {
   *     'name': 'John',
   *     'favourite_ids': [1, 2]
   *   }
   * }
   *
   * Response with embedded record:
   * {
   *   'member': {
   *     'name': 'John',
   *     'favourites': [{
   *         'id': 1,
   *         'name': 'Mark'
   *      },{
   *         'id': 2,
   *         'name': 'Maggie'
   *      }]
   *    }
   * }
   * 
   * @var array
   * @todo embeded records are deprecated (for now)
   */
  private static $embeddedRecords = array();	


  /**
   * Sideloaded records settings
   * 
   * @todo not implemented
   * 
   * @var array
   */
  private static $sideloadedRecords = array();


  /**
   * Search Filter Modifiers Separator used in the query var
   * i.e. ?column__EndsWith=value
   * 
   * @var string
   */
  private static $searchFilterModifiersSeparator = '__';


  /**
   * Set a maximum numbers of records returned by the API.
   * Only affectects "GET All". Useful to avoid returning millions of records at once.
   * 
   * Set to -1 to disable.
   * 
   * @var integer
   * @config
   */
  private static $max_records_limit = 100;


  /**
   * Stores the currently requested data
   * 
   * @var array
   */
  public $requestedData = array(
    'model'  => null,
    'id'     => null,
    'params' => null
  );


	/**
	 * Return current RESTfulAPI DeSerializer instance
   * 
	 * @return RESTfulAPI_DeSerializer DeSerializer instance
	 */
	public function getdeSerializer()
	{
		return $this->deSerializer;
	}

	
  /**
   * All requests pass through here and are redirected depending on HTTP verb and params
   * 
   * @param  SS_HTTPRequest                 $request    HTTP request
   * @return DataObjec|DataList|stdClass                DataObject/DataList result or stdClass on error
   */
  public function handleQuery(SS_HTTPRequest $request)
  { 
    //get requested model(s) details
    $model       = $request->param('ClassName');
    $id          = $request->param('ID');
    $response    = false;
    $queryParams = $this->parseQueryParameters( $request->getVars() );

    //validate Model name + store
    if ($model)
    {
      $model = $this->deSerializer->unformatName( $model );
      if ( !class_exists($model) )
      {
        return new RESTfulAPI_Error(400,
          "Model does not exist. Received '$model'."
        );
      }
      else{
        //store requested model data and query data
        $this->requestedData['model'] = $model;
      }
    }
    else{
      //if model missing, stop + return blank object
      return new RESTfulAPI_Error(400,
        "Missing Model parameter."
      );
    }
    
    //validate ID + store
    if ( ($request->isPUT() || $request->isDELETE()) && !is_numeric($id) )
    {
      return new RESTfulAPI_Error(400,
        "Invalid or missing ID. Received '$id'."
      );
    }
    else if ( $id !== NULL && !is_numeric($id) )
    {
      return new RESTfulAPI_Error(400,
        "Invalid ID. Received '$id'."
      );
    }
    else{
      $this->requestedData['id'] = $id;
    }

    //store query parameters
    if ($queryParams)
    {
      $this->requestedData['params'] = $queryParams;
    }

    //check API access rules on model
    if ( !$this->isAPIEnabled($model, $request->httpMethod()) )
    {
      return new RESTfulAPI_Error(403,
        "API access denied."
      );
    }

    //map HTTP word to module method
    if ( $request->isGET() )
    {
      $result = $this->findModel($model, $id, $queryParams, $request);
    }
    elseif ( $request->isPOST() )
    {
      $result = $this->createModel($model, $request);
    }
    elseif ( $request->isPUT() )
    {
      $result = $this->updateModel($model, $id, $request);
    }
    elseif ( $request->isDELETE() )
    {
      $result = $this->deleteModel($model, $id, $request);
    }
    else{
    	return new RESTfulAPI_Error(403,
        "HTTP method mismatch."
      );
    }
    
    return $result;
  }


  /**
   * Parse the query parameters to appropriate Column, Value, Search Filter Modifiers
   * array(
   *   array(
   *     'Column'   => ColumnName,
   *     'Value'    => ColumnValue,
   *     'Modifier' => ModifierType
   *   )
   * )
   * 
   * @param  array  $params raw GET vars array
   * @return array          formatted query parameters
   */
  function parseQueryParameters(array $params)
  {
    $parsedParams = array();
    $searchFilterModifiersSeparator = Config::inst()->get( 'RESTfulAPI_DefaultQueryHandler', 'searchFilterModifiersSeparator', Config::INHERITED );

    foreach ($params as $key__mod => $value)
    {
      //if ( strtoupper($key__mod) === 'URL' ) continue;
      // skip ul, flush, flushtoken
      if ( in_array(strtoupper($key__mod), array('URL', 'FLUSH', 'FLUSHTOKEN')) ) continue;

      $param = array();

      $key__mod = explode(
        $searchFilterModifiersSeparator,
        $key__mod
      );

      $param['Column'] = $this->deSerializer->unformatName( $key__mod[0] );

      $param['Value'] = $value;

      if ( isset($key__mod[1]) )
    	{
    		$param['Modifier'] = $key__mod[1];
    	}
      else{
      	$param['Modifier'] = null;
    	}

      array_push($parsedParams, $param);
    }

    return $parsedParams;
  }


 	/**
   * Finds 1 or more objects of class $model
   *
   * @todo  handle empty results? Return 404?
   * 
   * @param  string                 $model          Model(s) class to find
   * @param  boolean\integr         $id             The ID of the model to find or false
   * @param  array                  $queryParams    Query parameters and modifiers
   * @param  SS_HTTPRequest         $request        The original HTTP request
   * @return DataObject|DataList                    Result of the search (note: DataList can be empty) 
   */
  function findModel(string $model, $id = false, $queryParams, SS_HTTPRequest $request)
  {
    if ($id)
    {
    	$return = DataObject::get_by_id($model, $id);
    }
    else{
      // ":StartsWith", ":EndsWith", ":PartialMatch", ":GreaterThan", ":LessThan", ":Negation"
      // sort, rand, limit

      $return = DataList::create($model);

      if ( count($queryParams) > 0 )
      {
        foreach ($queryParams as $param)
        {

          if ( $param['Column'] )
          {
          	//@delete ?
            //$param['Column'] = Inflector::camelize( $param['Column'] );

            // handle sorting by column
            if ( $param['Modifier'] === 'sort' )
            {
              $return = $return->sort(array(
                $param['Column'] => $param['Value']
              ));
            }
            // normal modifiers / search filters
            else if ( $param['Modifier'] )
            {
              $return = $return->filter(array(
                $param['Column'].':'.$param['Modifier'] => $param['Value']
              ));
            }
            // no modifier / search filter
            else{
              $return = $return->filter(array(
                $param['Column'] => $param['Value']
              ));
            }
          }
          else{
            // random
            if ( $param['Modifier'] === 'rand' )
            {
              // rand + seed
              if ( $param['Value'] )
              {
                $return = $return->sort('RAND('.$param['Value'].')');
              }
              // rand only
              else{
                $return = $return->sort('RAND()');
              }
            }
            // limits
            else if ( $param['Modifier'] === 'limit' )
            {
              // range + offset
              if ( is_array($param['Value']) )
              {
                $return = $return->limit($param['Value'][0], $param['Value'][1]);
              }
              // range only
              else{
                $return = $return->limit($param['Value']);
              }
            }
          }

        }
      }

      //sets default limit if none given
      $limits = $return->dataQuery()->query()->getLimit();
      $limitConfig = Config::inst()->get('RESTfulAPI_DefaultQueryHandler', 'max_records_limit', Config::INHERITED);

      if ( is_array($limits) && !array_key_exists('limit', $limits) && $limitConfig >= 0 )
      {
        $return = $return->limit($limitConfig);
      }
    }

    return $return;
  }


  /**
   * Create object of class $model
   * 
   * @param  string         $model
   * @param  SS_HTTPRequest $request
   * @return DataObject
   */
  function createModel(string $model, SS_HTTPRequest $request)
  {
    $newModel = Injector::inst()->create($model);
    $newModel->write();

    return $this->updateModel($model, $newModel->ID, $request);
  }


  /**
   * Update databse record or $model
   *
   * @param String $model the model class to update
   * @param Integer $id The ID of the model to update
   * @param SS_HTTPRequest the original request
   *
   * @return DataObject The updated model 
   */
  function updateModel($model, $id, $request)
  {
    $model = DataObject::get_by_id($model, $id);
    if ( !$model )
    {
      return new RESTfulAPI_Error(404,
        "Record not found."
      );
    }

    $payload = $this->deSerializer->deserialize( $request->getBody() );
    if ( RESTfulAPI_Toolkit::is_error($payload) )
    {
      return $payload;
    }

    if ( $model && $payload )
    {
      $has_one            = Config::inst()->get( $model->ClassName, 'has_one', Config::INHERITED );
      $has_many           = Config::inst()->get( $model->ClassName, 'has_many', Config::INHERITED );
      $many_many          = Config::inst()->get( $model->ClassName, 'many_many', Config::INHERITED );
      $belongs_many_many  = Config::inst()->get( $model->ClassName, 'belongs_many_many', Config::INHERITED );

      $hasChanges         = false;
      $hasRelationChanges = false;

      foreach ($payload as $attribute => $value)
      {
        if ( !is_array($value) )
        {
          if ( array_key_exists($attribute, $has_one) )
          {
            $relation         = $attribute . 'ID';
            $model->$relation = $value;
            $hasChanges       = true;
          }
          else if ( $model->{$attribute} != $value )
          {
            $model->{$attribute} = $value;
            $hasChanges          = true;
          }
        }
        else{
          //has_many, many_many or $belong_many_many
          if ( array_key_exists($attribute, $has_many) || array_key_exists($attribute, $many_many) || array_key_exists($attribute, $belongs_many_many) )
          {
            $hasRelationChanges = true;
            $ssList = $model->{$attribute}();            
            $ssList->removeAll(); //reset list
            foreach ($value as $id)
            {
              $ssList->add( $id );              
            }
          }
        }
      }

      if ( $hasChanges || $hasRelationChanges )
      {
        $model->write(false, false, false, $hasRelationChanges);
      }
    }

    return DataObject::get_by_id($model->ClassName, $model->ID);
  }


  /**
   * Delete object of Class $model and ID $id
   *
   * @todo  Respond with a 204 status message on success?
   * 
   * @param  string          $model     Model class
   * @param  integer 				 $id        Model ID
   * @param  SS_HTTPRequest  $request   Model ID
   * @return NULL|array                 NULL if successful or array with error detail              
   */
  function deleteModel(string $model, integer $id, SS_HTTPRequest $request)
  {
    if ( $id )
    {
      $object = DataObject::get_by_id($model, $id);

      if ( $object )
      {
        $object->delete();
      }
      else{
        return new RESTfulAPI_Error(404,
          "Record not found."
        );
      }
    }
    else{
      //shouldn't happen but just in case
      return new RESTfulAPI_Error(400,
        "Invalid or missing ID. Received '$id'."
      );
    }
    
    return NULL;
  }


  /**
   * Checks and returns a model api_access config.
   * api_access config can be:
   * - unset, default to false
   * - false, access is always denied
   * - true, access is always granted
   * - comma separated list of allowed HTTP methods
   * 
   * @param  string  $model      Model's classname
   * @param  string  $httpMethod API request HTTP method
   * @return boolean             true if access is granted, false otherwise
   */
  function isAPIEnabled(string $model, string $httpMethod)
  {
    $rules = singleton($model)->stat('api_access');

    if ( is_string($rules) )
    {
      $rules = explode(',', strtoupper($rules));
      if ( in_array($httpMethod, $rules) )
      {
        return true;
      }
      else{
        return false;
      }
    }

    return $rules;
  }
}