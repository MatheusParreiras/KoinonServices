<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../model/dao/Conexao.php";
require_once __DIR__ . "/../service/Csrf.php";

class PermissaoController
{
    /*
    |--------------------------------------------------------------------------
    | PERFIS
    |--------------------------------------------------------------------------
    */

    public const PERFIL_MORADOR = "Morador";

    public const PERFIL_SINDICO = "Sindico";

    public const PERFIL_PORTEIRO = "Porteiro";

    /*
     * Administrador do condomínio.
     *
     * Este é o único perfil administrativo
     * de nível máximo do Koinon Services.
     */
    public const PERFIL_ADMIN = "Administrador";


    /*
    |--------------------------------------------------------------------------
    | LOGIN
    |--------------------------------------------------------------------------
    */

    public static function exigirLogin(
        bool $verificarLgpd = true
    ): void
    {
        if (
            empty($_SESSION["usuario_id"]) ||
            empty($_SESSION["usuario_perfil"])
        ) {
            header(
                "Location: /koinon-service/view/login.php"
            );

            exit;
        }

        /*
         * Proteção contra CSRF: ações por link (excluir, pagar, status...)
         * só funcionam com o token gerado pelo próprio sistema.
         */
        Csrf::proteger();

        /*
         * O estado da LGPD é conferido diretamente no banco.
         * Isso impede que uma sessão antiga ou manipulada permita
         * acesso sem o aceite registrado para o usuário atual.
         */
        if ($verificarLgpd) {

            try {

                $conexao = Conexao::conectar();

                $stmt = $conexao->prepare(
                    "SELECT termo_lgpd, data_aceite_lgpd, senha_definida
                     FROM usuario
                     WHERE id = :id
                     LIMIT 1"
                );

                $stmt->bindValue(
                    ":id",
                    (int) $_SESSION["usuario_id"],
                    PDO::PARAM_INT
                );

                $stmt->execute();

                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

                $lgpdAceito =
                    $usuario
                    && (int) ($usuario["termo_lgpd"] ?? 0) === 1
                    && !empty($usuario["data_aceite_lgpd"]);

                $_SESSION["lgpd_aceito"] = $lgpdAceito;

                if (!$lgpdAceito) {
                    header(
                        "Location: /koinon-service/view/lgpd.php"
                    );
                    exit;
                }

                if ((int) ($usuario["senha_definida"] ?? 0) !== 1) {
                    header(
                        "Location: /koinon-service/view/definir_senha.php"
                    );
                    exit;
                }

            } catch (Throwable $e) {

                /*
                 * Sem conseguir confirmar o aceite no banco, não libera
                 * o acesso às áreas protegidas.
                 */
                $_SESSION["lgpd_aceito"] = false;

                header(
                    "Location: /koinon-service/view/lgpd.php"
                );

                exit;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DADOS DO USUÁRIO LOGADO
    |--------------------------------------------------------------------------
    */

    public static function usuarioId(): ?int
    {
        if (
            !isset($_SESSION["usuario_id"])
        ) {
            return null;
        }

        return (int) $_SESSION["usuario_id"];
    }


    public static function usuarioNome(): ?string
    {
        return
            $_SESSION["usuario_nome"]
            ?? null;
    }


    public static function usuarioEmail(): ?string
    {
        return
            $_SESSION["usuario_email"]
            ?? null;
    }


    public static function usuarioPerfil(): ?string
    {
        return
            $_SESSION["usuario_perfil"]
            ?? null;
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAÇÃO DE PERFIL
    |--------------------------------------------------------------------------
    */

    public static function possuiPerfil(
        array $perfis
    ): bool {

        self::exigirLogin();

        $perfil =
            $_SESSION["usuario_perfil"]
            ?? null;

        return in_array(
            $perfil,
            $perfis,
            true
        );
    }


    public static function exigirPerfil(
        array $perfis
    ): void {

        self::exigirLogin();

        $perfil =
            $_SESSION["usuario_perfil"]
            ?? null;

        if (
            !in_array(
                $perfil,
                $perfis,
                true
            )
        ) {

            header(
                "Location: /koinon-service/view/acesso_negado.php"
            );

            exit;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ADMINISTRADOR
    |--------------------------------------------------------------------------
    */

    public static function exigirAdministrador(): void
    {
        self::exigirPerfil([
            self::PERFIL_ADMIN
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | GESTÃO
    |--------------------------------------------------------------------------
    |
    | A área de gestão pode ser utilizada por:
    |
    | - Administrador
    | - Síndico
    |
    */

    public static function exigirGestao(): void
    {
        self::exigirPerfil([
            self::PERFIL_ADMIN,
            self::PERFIL_SINDICO
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | PORTARIA
    |--------------------------------------------------------------------------
    |
    | A área de portaria pode ser utilizada por:
    |
    | - Administrador
    | - Síndico
    | - Porteiro
    |
    */

    public static function exigirPortaria(): void
    {
        self::exigirPerfil([
            self::PERFIL_ADMIN,
            self::PERFIL_SINDICO,
            self::PERFIL_PORTEIRO
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | MORADOR
    |--------------------------------------------------------------------------
    */

    public static function exigirMorador(): void
    {
        self::exigirPerfil([
            self::PERFIL_MORADOR
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | QUALQUER USUÁRIO LOGADO
    |--------------------------------------------------------------------------
    */

    public static function exigirUsuario(): void
    {
        self::exigirLogin();
    }


    /*
    |--------------------------------------------------------------------------
    | LOGOUT
    |--------------------------------------------------------------------------
    */

    public static function logout(): void
    {
        if (
            session_status()
            !== PHP_SESSION_ACTIVE
        ) {
            session_start();
        }

        $_SESSION = [];


        /*
         * Remove cookie da sessão.
         */

        if (
            ini_get(
                "session.use_cookies"
            )
        ) {

            $params =
                session_get_cookie_params();

            setcookie(
                session_name(),
                "",
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }


        /*
         * Destrói sessão.
         */

        session_destroy();


        /*
         * Volta para tela inicial.
         */

        header(
            "Location: /koinon-service/"
        );

        exit;
    }
}