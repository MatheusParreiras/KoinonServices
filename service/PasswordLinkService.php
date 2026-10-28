<?php

require_once __DIR__ . "/Configuracao.php";

class PasswordLinkService
{
    private string $storageDir;
    private array $config;

    public function __construct()
    {
        $this->storageDir = __DIR__ . "/../storage/password_resets";

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }

        $this->config = Configuracao::todas();
    }

    public function criarToken(int $usuarioId, int $minutos = 30): array
    {
        $token = bin2hex(random_bytes(32));
        $arquivo = $this->storageDir . "/" . hash("sha256", $token) . ".json";

        $dados = [
            "usuario_id" => $usuarioId,
            "expira_em" => time() + ($minutos * 60),
            "criado_em" => date("Y-m-d H:i:s"),
        ];

        file_put_contents(
            $arquivo,
            json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        return [
            "token" => $token,
            "expira_em" => $dados["expira_em"],
        ];
    }

    /*
     * Sem base_url, o endereço viria do cabeçalho Host da requisição,
     * que quem pede o link controla: o e-mail levaria a vítima para
     * outro site com o token. Só é aceito no modo demonstração.
     */
    private function exigirEnderecoDosLinks(): void
    {
        if (trim((string)($this->config["base_url"] ?? "")) === "" && !Configuracao::modoDemonstracao()) {
            throw new RuntimeException("Configure base_url em config_canais.php para enviar links de senha.");
        }
    }

    public function url(string $token): string
    {
        $this->exigirEnderecoDosLinks();

        $baseUrl = trim((string)($this->config["base_url"] ?? ""));
        if ($baseUrl !== "") {
            return rtrim($baseUrl, "/") . "/view/redefinir_senha.php?token=" . urlencode($token);
        }

        $protocolo = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https://" : "http://";
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";
        return $protocolo . $host . Configuracao::url("view/redefinir_senha.php") . "?token=" . urlencode($token);
    }

    /*
     * Cria um link de definição de senha e o envia pelo canal escolhido.
     *
     * O link só chega ao dono da conta (e-mail ou WhatsApp cadastrados).
     * Ele nunca é devolvido para a tela de quem pediu, exceto no modo
     * demonstração ("link_demo" / "whatsapp_demo"), quando o envio falha.
     *
     * @param array{id: int|string, nome: string, email: ?string, telefone: ?string} $usuario
     * @return array{enviado: bool, link_demo: ?string, whatsapp_demo: ?string}
     */
    public function enviarLinkSenha(array $usuario, string $canal): array
    {
        $resultado = ["enviado" => false, "link_demo" => null, "whatsapp_demo" => null];

        try {
            $this->exigirEnderecoDosLinks();
            $link = $this->url($this->criarToken((int) $usuario["id"])["token"]);
        } catch (RuntimeException $e) {
            error_log("[Koinon] " . $e->getMessage());
            return $resultado;
        }

        $demo = Configuracao::modoDemonstracao();

        if ($canal === "whatsapp") {
            $envio = $this->enviarWhatsApp(
                (string) ($usuario["telefone"] ?? ""),
                (string) $usuario["nome"],
                $link
            );
            $resultado["enviado"] = $envio["enviado"];

            // O click-to-chat abre o WhatsApp de quem pediu com o link no texto.
            if (!$envio["enviado"] && $demo) {
                $resultado["whatsapp_demo"] = $envio["url"];
                $resultado["link_demo"] = $link;
            }

            return $resultado;
        }

        $resultado["enviado"] = $this->enviarEmail(
            (string) ($usuario["email"] ?? ""),
            (string) $usuario["nome"],
            $link
        );

        if (!$resultado["enviado"] && $demo) {
            $resultado["link_demo"] = $link;
        }

        return $resultado;
    }

    public function enviarEmail(string $destinatario, string $nome, string $link): bool
    {
        if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) return false;
        $cfg = (array)($this->config["email"] ?? []);
        $provider = strtolower((string)($cfg["provedor"] ?? "mail"));
        $fromName = (string)($cfg["remetente_nome"] ?? "Koinon Services");
        $fromEmail = (string)($cfg["remetente_email"] ?? "noreply@localhost");
        $subject = "Koinon Services - Definição de senha";
        $text = "Olá, {$nome}.\n\nFoi solicitada uma definição/redefinição de senha para sua conta no Koinon Services.\n\nLink seguro (válido por 30 minutos e de uso único):\n{$link}\n\nSe você não solicitou esta ação, ignore esta mensagem.";

        if ($provider === "resend" && function_exists("curl_init")) {
            $apiKey = trim((string)($cfg["resend_api_key"] ?? ""));
            if ($apiKey === "") return false;
            $payload = [
                "from" => $fromName . " <" . $fromEmail . ">",
                "to" => [$destinatario],
                "subject" => $subject,
                "text" => $text,
                "html" => "<p>Olá, " . htmlspecialchars($nome, ENT_QUOTES, "UTF-8") . ".</p><p>Use o link abaixo para definir/redefinir sua senha:</p><p><a href=\"" . htmlspecialchars($link, ENT_QUOTES, "UTF-8") . "\">Definir minha senha</a></p><p>O link é válido por 30 minutos e pode ser usado uma única vez.</p>",
            ];
            $ch = curl_init("https://api.resend.com/emails");
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $apiKey, "Content-Type: application/json"],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $status >= 200 && $status < 300;
        }

        $headers = "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$fromEmail}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        return function_exists("mail") && @mail($destinatario, $subject, $text, $headers);
    }

    public function enviarWhatsApp(string $telefone, string $nome, string $link): array
    {
        $telefone = preg_replace('/\D+/', '', $telefone);

        if ($telefone === "") {
            return ["enviado" => false, "modo" => "invalido", "url" => null];
        }

        $wa = (array) ($this->config["whatsapp"] ?? []);
        $habilitado = !empty($wa["habilitado"]);
        $phoneId = trim((string) ($wa["phone_number_id"] ?? ""));
        $token = trim((string) ($wa["access_token"] ?? ""));
        $version = trim((string) ($wa["graph_version"] ?? "v23.0"));

        /*
         * Se a API oficial estiver configurada, envia automaticamente.
         * Sem credenciais, gera um link click-to-chat para o teste/apresentação.
         */
        if ($habilitado && $phoneId !== "" && $token !== "" && function_exists("curl_init")) {
            $endpoint = "https://graph.facebook.com/{$version}/{$phoneId}/messages";

            $templateName = trim((string)($wa["template_name"] ?? "koinon_redefinicao_senha"));
            $templateLanguage = trim((string)($wa["template_language"] ?? "pt_BR"));
            $payload = [
                "messaging_product" => "whatsapp",
                "to" => $telefone,
                "type" => "template",
                "template" => [
                    "name" => $templateName,
                    "language" => ["code" => $templateLanguage],
                    "components" => [[
                        "type" => "body",
                        "parameters" => [
                            ["type" => "text", "text" => $nome],
                            ["type" => "text", "text" => $link],
                        ],
                    ]],
                ],
            ];

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $token,
                    "Content-Type: application/json",
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => 15,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return ["enviado" => true, "modo" => "api", "url" => null];
            }
        }

        $mensagem = rawurlencode(
            "Olá, {$nome}! Seu link seguro do Koinon Services para definir/redefinir sua senha é: {$link} (válido por 30 minutos)."
        );

        return [
            "enviado" => false,
            "modo" => "click_to_chat",
            "url" => "https://wa.me/{$telefone}?text={$mensagem}",
        ];
    }
}
