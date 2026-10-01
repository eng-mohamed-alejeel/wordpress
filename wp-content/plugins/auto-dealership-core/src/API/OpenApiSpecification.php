<?php
namespace AutoDealership\API;

defined( 'ABSPATH' ) || exit;

/** Generates a bounded OpenAPI 3.1 description from the registered v2 routes. */
final class OpenApiSpecification {
	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ), 20 );
	}

	public static function register(): void {
		register_rest_route( 'auto-dealership/schema', '/v2', array(
			'methods'=>'GET',
			'permission_callback'=>static fn()=>current_user_can( 'adc_view_workspace' ) || current_user_can( 'manage_options' ),
			'callback'=>static fn()=>rest_ensure_response( self::document() ),
		) );
	}

	public static function document(): array {
		$paths = array();
		$routes = rest_get_server()->get_routes( 'auto-dealership/v2' );
		foreach ( $routes as $route=>$endpoints ) {
			$relative = preg_replace( '#\A/auto-dealership/v2#', '', $route );
			$path = preg_replace( '#\(\?P<([a-zA-Z0-9_]+)>[^)]+\)#', '{$1}', $relative );
			foreach ( $endpoints as $endpoint ) {
				foreach ( array_keys( array_filter( (array) ( $endpoint['methods'] ?? array() ) ) ) as $method ) {
					$method = strtolower( $method );
					if ( ! in_array( $method, array( 'get','post','put','patch','delete' ), true ) ) { continue; }
					$args = (array) ( $endpoint['args'] ?? array() );
					$parameters = array();
					$body = array();
					$required = array();
					foreach ( $args as $name=>$definition ) {
						$schema = self::schema( is_array( $definition ) ? $definition : array() );
						if ( str_contains( $path, '{' . $name . '}' ) || 'get' === $method ) {
							$parameters[] = array( 'name'=>$name, 'in'=>str_contains( $path, '{' . $name . '}' ) ? 'path' : 'query', 'required'=>str_contains( $path, '{' . $name . '}' ) || ! empty( $definition['required'] ), 'schema'=>$schema );
						} else {
							$body[$name] = $schema;
							if ( ! empty( $definition['required'] ) ) { $required[] = $name; }
						}
					}
					$operation = array(
						'operationId'=>self::operation_id( $method, $path ),
						'parameters'=>$parameters,
						'responses'=>array(
							'200'=>array( 'description'=>'Successful v2 envelope', 'content'=>array( 'application/json'=>array( 'schema'=>array( '$ref'=>'#/components/schemas/SuccessEnvelope' ) ) ) ),
							'default'=>array( 'description'=>'Error v2 envelope', 'content'=>array( 'application/json'=>array( 'schema'=>array( '$ref'=>'#/components/schemas/ErrorEnvelope' ) ) ) ),
						),
					);
					if ( $body ) {
						$body_schema = array( 'type'=>'object', 'properties'=>$body, 'additionalProperties'=>false );
						if ( $required ) { $body_schema['required'] = array_values( array_unique( $required ) ); }
						$operation['requestBody'] = array( 'required'=>(bool) $required, 'content'=>array( 'application/json'=>array( 'schema'=>$body_schema ) ) );
					}
					$key = strtoupper( $method ) . ' ' . $relative;
					if ( ! isset( Routes::PUBLIC_ENDPOINTS[$key] ) ) { $operation['security'] = array( array( 'cookieNonce'=>array() ) ); }
					$paths[$path][$method] = $operation;
				}
			}
		}
		ksort( $paths );
		return array(
			'openapi'=>'3.1.0',
			'info'=>array( 'title'=>'Auto Dealership API', 'version'=>defined( 'ADC_VERSION' ) ? ADC_VERSION : 'development' ),
			'servers'=>array( array( 'url'=>rest_url( 'auto-dealership/v2' ) ) ),
			'paths'=>$paths,
			'components'=>array(
				'securitySchemes'=>array( 'cookieNonce'=>array( 'type'=>'apiKey', 'in'=>'header', 'name'=>'X-WP-Nonce', 'description'=>'WordPress cookie authentication REST nonce.' ) ),
				'schemas'=>array(
					'SuccessEnvelope'=>array( 'type'=>'object', 'required'=>array( 'success','data','meta' ), 'properties'=>array( 'success'=>array( 'const'=>true ), 'data'=>array(), 'meta'=>array( '$ref'=>'#/components/schemas/ResponseMeta' ) ) ),
					'ErrorEnvelope'=>array( 'type'=>'object', 'required'=>array( 'success','error','meta' ), 'properties'=>array( 'success'=>array( 'const'=>false ), 'error'=>array( 'type'=>'object', 'required'=>array( 'code','message','details' ), 'properties'=>array( 'code'=>array( 'type'=>'string' ), 'message'=>array( 'type'=>'string' ), 'details'=>array( 'type'=>'object' ) ) ), 'meta'=>array( '$ref'=>'#/components/schemas/ResponseMeta' ) ) ),
					'ResponseMeta'=>array( 'type'=>'object', 'required'=>array( 'request_id' ), 'properties'=>array( 'request_id'=>array( 'type'=>'string' ) ) ),
				),
			),
		);
	}

	private static function schema( array $definition ): array {
		$schema = array( 'type'=>(string) ( $definition['type'] ?? 'string' ) );
		foreach ( array( 'enum','minimum','maximum','minLength','maxLength','pattern','default','items' ) as $key ) { if ( array_key_exists( $key, $definition ) ) { $schema[$key] = $definition[$key]; } }
		return $schema;
	}

	private static function operation_id( string $method, string $path ): string {
		return trim( preg_replace( '/[^a-zA-Z0-9]+/', '_', $method . '_' . trim( $path, '/' ) ), '_' );
	}
}
