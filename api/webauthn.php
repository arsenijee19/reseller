<?php
declare(strict_types=1);

use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

function webauthn_config(): array {
  $config = config_value('security.webauthn', []);
  return [
    'rp_id' => trim((string)($config['rp_id'] ?? 'reseller.psigre.rs')),
    'origin' => rtrim(trim((string)($config['origin'] ?? config_value('security.origin', 'https://reseller.psigre.rs'))), '/'),
    'rp_name' => trim((string)($config['rp_name'] ?? 'PlayWorld.rs Admin')),
  ];
}

function webauthn_serializer(): Symfony\Component\Serializer\SerializerInterface {
  static $serializer = null;
  if ($serializer instanceof Symfony\Component\Serializer\SerializerInterface) return $serializer;
  $manager = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
  $serializer = (new WebauthnSerializerFactory($manager))->create();
  return $serializer;
}

function webauthn_ceremony_factory(): CeremonyStepManagerFactory {
  $factory = new CeremonyStepManagerFactory();
  $factory->setAllowedOrigins([webauthn_config()['origin']], false);
  return $factory;
}

function webauthn_user_entity(array $admin): PublicKeyCredentialUserEntity {
  $adminId = (int)($admin['id'] ?? 0);
  $username = trim((string)($admin['username'] ?? 'admin'));
  return PublicKeyCredentialUserEntity::create(
    $username,
    hash('sha256', 'playworld-owner:' . $adminId, true),
    $username
  );
}

function webauthn_creation_options(array $admin, string $challenge, array $credentialIds = []): PublicKeyCredentialCreationOptions {
  $exclude = [];
  foreach ($credentialIds as $credentialId) {
    $exclude[] = PublicKeyCredentialDescriptor::create(
      PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
      (string)$credentialId
    );
  }
  $config = webauthn_config();
  return PublicKeyCredentialCreationOptions::create(
    PublicKeyCredentialRpEntity::create($config['rp_name'], $config['rp_id']),
    webauthn_user_entity($admin),
    $challenge,
    [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
    AuthenticatorSelectionCriteria::create(
      null,
      AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
      AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
    ),
    PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
    $exclude,
    60000
  );
}

function webauthn_request_options(string $challenge, array $credentialIds = []): PublicKeyCredentialRequestOptions {
  $allow = [];
  foreach ($credentialIds as $credentialId) {
    $allow[] = PublicKeyCredentialDescriptor::create(
      PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
      (string)$credentialId
    );
  }
  return PublicKeyCredentialRequestOptions::create(
    $challenge,
    webauthn_config()['rp_id'],
    $allow,
    AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
    60000
  );
}

function webauthn_json_options(object $options): array {
  $json = webauthn_serializer()->serialize($options, 'json');
  $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
  return is_array($decoded) ? $decoded : [];
}

function webauthn_load_credential_record(string $json): CredentialRecord {
  $record = webauthn_serializer()->deserialize($json, CredentialRecord::class, 'json');
  if (!$record instanceof CredentialRecord) throw new RuntimeException('Passkey zapis nije validan.');
  return $record;
}

function webauthn_validate_registration(string $responseJson, PublicKeyCredentialCreationOptions $options): CredentialRecord {
  $credential = webauthn_serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');
  if (!$credential instanceof PublicKeyCredential || !$credential->response instanceof Webauthn\AuthenticatorAttestationResponse) {
    throw new RuntimeException('Passkey registracija nije validna.');
  }
  $host = parse_url(webauthn_config()['origin'], PHP_URL_HOST);
  if (!is_string($host) || $host === '') throw new RuntimeException('WebAuthn origin nije konfigurisan.');
  return AuthenticatorAttestationResponseValidator::create(webauthn_ceremony_factory()->creationCeremony())
    ->check($credential->response, $options, $host);
}

function webauthn_validate_assertion(string $responseJson, CredentialRecord $record, PublicKeyCredentialRequestOptions $options): CredentialRecord {
  $credential = webauthn_serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');
  if (!$credential instanceof PublicKeyCredential || !$credential->response instanceof Webauthn\AuthenticatorAssertionResponse) {
    throw new RuntimeException('Passkey prijava nije validna.');
  }
  if (!hash_equals($record->publicKeyCredentialId, $credential->rawId)) {
    throw new RuntimeException('Passkey prijava nije validna.');
  }
  $host = parse_url(webauthn_config()['origin'], PHP_URL_HOST);
  if (!is_string($host) || $host === '') throw new RuntimeException('WebAuthn origin nije konfigurisan.');
  return AuthenticatorAssertionResponseValidator::create(webauthn_ceremony_factory()->requestCeremony())
    ->check($record, $credential->response, $options, $host, $credential->response->userHandle);
}

function webauthn_new_challenge(): string {
  return random_bytes(32);
}

function webauthn_challenge_key(string $challenge): string {
  return rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');
}

function webauthn_response_challenge_key(string $responseJson): string {
  $response = json_decode($responseJson, true, 32, JSON_THROW_ON_ERROR);
  $clientData = (string)($response['response']['clientDataJSON'] ?? '');
  $decoded = base64_decode(strtr($clientData, '-_', '+/') . str_repeat('=', (4 - strlen($clientData) % 4) % 4), true);
  $client = is_string($decoded) ? json_decode($decoded, true, 32, JSON_THROW_ON_ERROR) : [];
  $challenge = (string)($client['challenge'] ?? '');
  if ($challenge === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $challenge)) throw new RuntimeException('Passkey challenge nije validan.');
  return $challenge;
}

function webauthn_session_hash(): string {
  return hash('sha256', session_id());
}

function webauthn_store_challenge(PDO $pdo, string $ceremony, string $challenge, object $options): void {
  $challengeKey = webauthn_challenge_key($challenge);
  $pdo->prepare('DELETE FROM owner_webauthn_challenges WHERE session_id_hash = ? OR expires_at < NOW()')->execute([webauthn_session_hash()]);
  $pdo->prepare('INSERT INTO owner_webauthn_challenges (session_id_hash, ceremony, challenge, options_json, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 SECOND))')
    ->execute([webauthn_session_hash(), $ceremony, $challengeKey, webauthn_serializer()->serialize($options, 'json')]);
}

function webauthn_take_challenge(PDO $pdo, string $ceremony, string $challenge): ?array {
  $stmt = $pdo->prepare('SELECT * FROM owner_webauthn_challenges WHERE session_id_hash = ? AND ceremony = ? AND challenge = ? AND used_at IS NULL AND expires_at >= NOW() LIMIT 1');
  $stmt->execute([webauthn_session_hash(), $ceremony, $challenge]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  $update = $pdo->prepare('UPDATE owner_webauthn_challenges SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
  $update->execute([(int)$row['id']]);
  return $update->rowCount() === 1 ? $row : null;
}
