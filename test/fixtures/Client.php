<?php

namespace Tinify;

class Client {
	const API_ENDPOINT = 'http://tinify-mock-api';

	private $options;

	public static function userAgent() {
		$curl = curl_version();
		return 'Tinify/' . VERSION . ' PHP/' . PHP_VERSION . ' curl/' . $curl['version'];
	}

	private static function caBundle() {
		return __DIR__ . '/../data/cacert.pem';
	}

	function __construct( $key, $appIdentifier = null ) {
		$userAgent = join( ' ', array_filter( array( self::userAgent(), $appIdentifier ) ) );
		$this->options = array(
			CURLOPT_BINARYTRANSFER => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_USERPWD => $key ? ('api:' . $key) : null,
			CURLOPT_USERAGENT => $userAgent,
		);
	}

	function request( $method, $url, $body = null, $header = array() ) {
		if ( is_array( $body ) ) {
			if ( ! empty( $body ) ) {
				$body = json_encode( $body );
				array_push( $header, 'Content-Type: application/json' );
			} else {
				$body = null;
			}
		}

		$request = curl_init();
		curl_setopt_array( $request, $this->options );

		$url = strtolower( substr( $url, 0, 5 ) ) == 'http:' ? $url : self::API_ENDPOINT . $url;
		curl_setopt( $request, CURLOPT_URL, $url );
		curl_setopt( $request, CURLOPT_HTTPHEADER, $header );
		curl_setopt( $request, CURLOPT_CUSTOMREQUEST, strtoupper( $method ) );

		if ( $body ) {
			curl_setopt( $request, CURLOPT_POSTFIELDS, $body );
		}

		$response = curl_exec( $request );

		if ( is_string( $response ) ) {
			$status = curl_getinfo( $request, CURLINFO_HTTP_CODE );
			$headerSize = curl_getinfo( $request, CURLINFO_HEADER_SIZE );
			curl_close( $request );

			$headers = self::parseHeaders( substr( $response, 0, $headerSize ) );
			$body = substr( $response, $headerSize );

			if ( isset( $headers['compression-count'] ) ) {
				Tinify::setCompressionCount( intval( $headers['compression-count'] ) );
			}

			if ( isset( $headers["compression-count-remaining"] ) ) {
				Tinify::setRemainingCredits( intval( $headers["compression-count-remaining"] ) );
			}

			if ( isset( $headers["paying-state"] ) ) {
				Tinify::setPayingState( $headers["paying-state"] );
			}

			if ( isset( $headers["email-address"] ) ) {
				Tinify::setEmailAddress( $headers["email-address"] );
			}

			$isJson = false;
			if ( isset( $headers['content-type'] ) ) {
				/* Parse JSON response bodies. */
				list($contentType) = explode( ';', $headers['content-type'], 2 );
				if ( strtolower( trim( $contentType ) ) == 'application/json' ) {
					$isJson = true;
				}
			}

			/* 1xx and 3xx are unexpected and will be treated as error. */
			$isError = $status <= 199 || $status >= 300;

			if ( $isJson || $isError ) {
				/* Parse JSON bodies, always interpret errors as JSON. */
				$body = json_decode( $body );
				if ( ! $body ) {
					$message = sprintf('Error while parsing response: %s (#%d)',
						PHP_VERSION_ID >= 50500 ? json_last_error_msg() : 'Error',
					json_last_error());
					throw Exception::create( $message, 'ParseError', $status );
				}
			}

			if ( $isError ) {
				throw Exception::create( $body->message, $body->error, $status );
			}

			return (object) array(
				'body' => $body,
				'headers' => $headers,
			);
		} else {
			$message = sprintf( '%s (#%d)', curl_error( $request ), curl_errno( $request ) );
			curl_close( $request );
			throw new ConnectionException( 'Error while connecting: ' . $message );
		}// End if().
	}

	protected static function parseHeaders( $headers ) {
		if ( ! is_array( $headers ) ) {
			$headers = explode( "\r\n", $headers );
		}

		$result = array();
		foreach ( $headers as $header ) {
			if ( empty( $header ) ) {
				continue;
			}

			$split = explode( ':', $header, 2 );
			if ( count( $split ) === 2 ) {
				$result[ strtolower( $split[0] ) ] = trim( $split[1] );
			}
		}
		return $result;
	}
}
