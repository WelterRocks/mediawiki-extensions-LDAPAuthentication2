<?php

namespace MediaWiki\Extension\LDAPAuthentication2;

use Exception;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\LDAPProvider\ClientConfig;
use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\LDAPNoDomainConfigException as NoDomain;
use MediaWiki\Extension\LDAPProvider\UserDomainStore;
use MediaWiki\MediaWikiServices;
use MWException;
use PluggableAuth as PluggableAuthBase;
use PluggableAuthLogin;
use User;

class PluggableAuth extends PluggableAuthBase {

	const DOMAIN_SESSION_KEY = 'ldap-authentication-selected-domain';
	
	private $default_login = false;

	/**
	 * Authenticates against LDAP
	 * @param int &$id not used
	 * @param string &$username set to username
	 * @param string &$realname set to real name
	 * @param string &$email set to email
	 * @param string &$errorMessage any errors
	 * @return bool false on failure
	 * @SuppressWarnings( UnusedFormalParameter )
	 * @SuppressWarnings( ShortVariable )
	 */
	public function authenticate( &$id, &$username, &$realname, &$email, &$errorMessage ) {
		$authManager = $this->getAuthManager();

		$config = Config::newInstance();
		$sslconfig = $config::getSSLConfig();

		$extraLoginFields = $authManager->getAuthenticationSessionData(
			PluggableAuthLogin::EXTRALOGINFIELDS_SESSION_KEY
		);

		$domain = $extraLoginFields[ExtraLoginFields::DOMAIN];
		$password = null;
		$username = null;
		
		if ($sslconfig->enabled)
		{
			if ($config::getSSLUsername())
			{
				if ($sslconfig->requirePassword)
					$password = $extraLoginFields[ExtraLoginFields::PASSWORD];
				else
					$password = null;
				
				$username = $extraLoginFields[ExtraLoginFields::SSLUSER];
			}
			else
			{
				$this->default_login = true;
			}
		}
		else
		{
			$this->default_login = true;
		}
		
		if ($this->default_login)
		{
			$password = $extraLoginFields[ExtraLoginFields::PASSWORD];
			$username = $extraLoginFields[ExtraLoginFields::USERNAME];
		}

		$isLocal = $this->maybeLocalLogin( $domain, $username, $password, $id, $errorMessage );
		if ( $isLocal !== null ) {
			return $isLocal;
		}

		if ( !$this->checkLDAPLogin(
			$domain, $username, $password, $realname, $email, $errorMessage
		) ) {
			return false;
		}

		$username = $this->normalizeUsername( $username );
		$user = User::newFromName( $username );
		if ( $user !== false && $user->getId() !== 0 ) {
			$id = $user->getId();
		}

		return true;
	}

	/**
	 * Normalize usernames as desired.
	 *
	 * @param string $username to normalize
	 * @return string username with any normalization
	 */
	protected function normalizeUsername( $username ) {
		/**
		 * this is a feature after updating wikis which used strtolower on usernames.
		 * to use it, set this in LocalSettings.php:
		 * $LDAPAuthentication2UsernameNormalizer = 'strtolower';
		 */
		$config = Config::newInstance();
		$normalizer = $config->get( "UsernameNormalizer" );
		if ( !empty( $normalizer ) ) {
			if ( !is_callable( $normalizer ) ) {
				throw new MWException(
					"The UsernameNormalizer for LDAPAuthentiation2 should be callable"
				);
			}
			$username = call_user_func( $normalizer, $username );
		}
		return $username;
	}

	/**
	 * If a local login is attempted, see if they're allowed, try it if they
	 * are, and return success or faillure.  Otherwise, if no local login is
	 * attempted, return null.
	 *
	 * @param string $domain we are logging into
	 * @param string &$username for the user
	 * @param string $password for the user
	 * @param int &$id value of id
	 * @param string &$errorMessage any error message for the user
	 *
	 * @return ?bool
	 */
	protected function maybeLocalLogin(
		$domain,
		&$username,
		$password,
		&$id,
		&$errorMessage
	) {
		if ( $domain === ExtraLoginFields::DOMAIN_VALUE_LOCAL ) {
			$config = Config::newInstance();
			$sslconfig = $config::getSSLConfig();			
			if ( !$config->get( "AllowLocalLogin" ) ) {
				$errorMessage = wfMessage( 'ldapauthentication2-no-local-login' )->plain();
				return false;
			}
			
			if (!$this->default_login)
			{
				if ($sslconfig->enabled)
				{
					if ($username != $config::getSSLUsername())
						return false;
				}
			}

			if (($sslconfig->enabled) && (!$sslconfig->requirePassword) && (!$this->default_login))
			{
				// Passwordless login via SSL client cert
				$user = User::newFromName( $username );				
			}
			else
			{
				// Validate local user the mediawiki way
				$user = $this->checkLocalPassword( $username, $password );
			}

			if ( $user ) {
				$id = $user->getId();
				$username = $user->getName();
				return true;
			}
			
			$errorMessage = wfMessage(
				'ldapauthentication2-error-local-authentication-failed'
			)->plain();
			return false;
		}

		return null;
	}

	/**
	 * Attempt a login and get info (realname, username) from LDAP
	 *
	 * @param string $domain
	 * @param string &$username username used for binding is passed in, but
	 *     chosen attribute is returned here
	 * @param string $password
	 * @param string &$realname Real name from LDAP
	 * @param string &$email for the user from LDAP
	 * @param string &$errorMessage any error message for the user
	 *
	 * @return ?bool
	 */
	protected function checkLDAPLogin(
		$domain,
		&$username,
		$password,
		&$realname,
		&$email,
		&$errorMessage
	) {
		/* This is a workaround: As "PluggableAuthUserAuthorization" hook is
		 * being called before PluggableAuth::saveExtraAttributes (see below)
		 * we can not rely on LdapProvider\UserDomainStore here. Further
		 * complicating things, we can not persist the domain here, as the
		 * user id may be null (first login)
		 */
		$authManager = $this->getAuthManager();
		$authManager->setAuthenticationSessionData(
			static::DOMAIN_SESSION_KEY,
			$domain
		);

		$ldapClient = null;
		try {
			$ldapClient = ClientFactory::getInstance()->getForDomain( $domain );
		} catch ( NoDomain $e ) {
			$errorMessage = wfMessage( 'ldapauthentication2-no-domain-chosen' )->plain();
			return false;
		}

		$config = Config::newInstance();
		$sslconfig = $config::getSSLConfig();
		
		if (!$this->default_login)
		{
			if ($sslconfig->enabled)
			{
				if ($username != $config::getSSLUsername())
					return false;
			}
		}			

		if ((($sslconfig->enabled) && ($sslconfig->requirePassword)) || (!$sslconfig->enabled) || ($this->default_login))
		{
			if ( !$ldapClient->canBindAs( $username, $password ) ) {
				$errorMessage = wfMessage(
					'ldapauthentication2-error-authentication-failed', $domain
				)->text();
				return false;
			}
		}

		try {
			$result = $ldapClient->getUserInfo( $username );
			$username = $result[$ldapClient->getConfig( ClientConfig::USERINFO_USERNAME_ATTR )];
			$realname = $result[$ldapClient->getConfig( ClientConfig::USERINFO_REALNAME_ATTR )];
			// maybe there are no emails stored in LDAP, this prevents php notices:
			$email = $result[$ldapClient->getConfig( ClientConfig::USERINFO_EMAIL_ATTR )] ?? '';
		} catch ( Exception $ex ) {
			$errorMessage = wfMessage(
				'ldapauthentication2-error-authentication-failed-userinfo', $domain
			)->text();

			wfDebugLog( 'LDAPAuthentication2', "Error fetching userinfo: {$ex->getMessage()}" );
			wfDebugLog( 'LDAPAuthentication2', $ex->getTraceAsString() );

			return false;
		}

		return true;
	}

	/**
	 * @param User &$user to log out
	 */
	public function deauthenticate( User &$user ) {
		// Nothing to do, really
		$user = null;
	}

	/**
	 * @param int $userId for user
	 */
	public function saveExtraAttributes( $userId ) {
		$authManager = $this->getAuthManager();
		$domain = $authManager->getAuthenticationSessionData(
			static::DOMAIN_SESSION_KEY
		);

		/**
		 * This can happen, when user account creation was initiated by a foreign source
		 * (e.g Auth_remoteuser). There is no way of knowing the domain at this point.
		 * This can also not be a local login attempt as it would be caught in `authenticate`.
		 */
		if ( $domain === null ) {
			return;
		}
		$userDomainStore = new UserDomainStore(
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);

		$userDomainStore->setDomainForUser(
			\User::newFromId( $userId ),
			$domain
		);
	}

	/**
	 * Return user if the authentication is successful, null otherwise.
	 *
	 * @param string $username
	 * @param string $password
	 * @return ?User
	 */
	protected function checkLocalPassword( $username, $password ) {
		$user = User::newFromName( $username );
		$services = MediaWikiServices::getInstance();
		$passwordFactory = $services->getPasswordFactory();

		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$row = $dbr->selectRow( 'user', 'user_password', [ 'user_name' => $user->getName() ] );
		$passwordInDB = $passwordFactory->newFromCiphertext( $row->user_password );

		return $passwordInDB->verify( $password ) ? $user : null;
	}

	/**
	 * Provide a getter for the AuthManager to abstract out version checking.
	 *
	 * @return AuthManager
	 */
	protected function getAuthManager() {
		if ( method_exists( MediaWikiServices::class, 'getAuthManager' ) ) {
			// MediaWiki 1.35+
			$authManager = MediaWikiServices::getInstance()->getAuthManager();
		} else {
			$authManager = AuthManager::singleton();
		}
		return $authManager;
	}
}
