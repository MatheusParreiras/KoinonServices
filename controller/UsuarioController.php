<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../model/dao/UsuarioDAO.php";
require_once __DIR__ . "/../model/dto/UsuarioDTO.php";

class UsuarioController
{
    private UsuarioDAO $dao;

    public function __construct()
    {
        $this->dao = new UsuarioDAO();
    }

    /*
     * LOGIN
     */
    public function login(
        string $email,
        string $senha
    ): bool {

        $usuario = $this->dao->buscarPorEmail($email);

        if (!$usuario) {
            return false;
        }

        if (!$usuario->getAtivo()) {
            return false;
        }

        if (
            !password_verify(
                $senha,
                $usuario->getSenhaHash()
            )
        ) {
            return false;
        }

        $_SESSION["usuario_id"] =
            $usuario->getId();

        $_SESSION["usuario_nome"] =
            $usuario->getNome();

        $_SESSION["usuario_perfil"] =
            $usuario->getPerfil();

        return true;
    }

    /*
     * LISTAR USUÁRIOS
     */
    public function listar(): array
    {
        return $this->dao->listar();
    }

    /*
     * BUSCAR USUÁRIO POR ID
     */
    public function buscarPorId(
        int $id
    ): ?UsuarioDTO {

        return $this->dao->buscarPorId($id);
    }

    /*
     * BUSCAR USUÁRIO POR E-MAIL
     */
    public function buscarPorEmail(
        string $email
    ): ?UsuarioDTO {

        return $this->dao->buscarPorEmail($email);
    }

    /*
     * BUSCAR USUÁRIO POR CPF
     */
    public function buscarPorCpf(
        string $cpf
    ): ?UsuarioDTO {

        return $this->dao->buscarPorCpf($cpf);
    }

    /*
     * VERIFICAR E-MAIL
     */
    public function emailExiste(
        string $email,
        ?int $idIgnorar = null
    ): bool {

        return $this->dao->emailExiste(
            $email,
            $idIgnorar
        );
    }

    /*
     * VERIFICAR CPF
     */
    public function cpfExiste(
        string $cpf,
        ?int $idIgnorar = null
    ): bool {

        return $this->dao->cpfExiste(
            $cpf,
            $idIgnorar
        );
    }

    /*
     * CADASTRAR USUÁRIO
     */
    public function cadastrar(
        UsuarioDTO $usuario
    ): bool {

        return $this->dao->cadastrar($usuario);
    }

    /*
     * EDITAR USUÁRIO
     */
    public function editar(
        UsuarioDTO $usuario
    ): bool {

        return $this->dao->editar($usuario);
    }

    /*
     * DESATIVAR USUÁRIO
     */
    public function excluir(
        int $id
    ): bool {

        return $this->dao->excluir($id);
    }

    /*
     * REATIVAR USUÁRIO
     */
    public function reativar(
        int $id
    ): bool {

        return $this->dao->reativar($id);
    }

    /*
     * REGISTRAR ACEITE DA LGPD
     */
    public function aceitarLgpd(int $id): bool
    {

        return $this->dao->aceitarLgpd($id);
    }


    /*
     * ALTERAR SENHA
     */
    public function alterarSenha(
        int $id,
        string $senhaHash
    ): bool {

        return $this->dao->alterarSenha(
            $id,
            $senhaHash
        );
    }
}


/*
|--------------------------------------------------------------------------
| AÇÕES DIRETAS DO CONTROLLER
|--------------------------------------------------------------------------
|
| Permite que o index.php execute:
|
| ?acao=excluir&id=1
| ?acao=reativar&id=1
|
*/

if (
    isset($_GET["acao"]) &&
    isset($_GET["id"])
) {

    /*
     * Somente Administrador pode
     * desativar ou reativar usuários.
     */
    require_once __DIR__ . "/PermissaoController.php";

    PermissaoController::exigirAdministrador();

    $acao = $_GET["acao"];
    $id = (int) $_GET["id"];

    if ($id <= 0) {
        header("Location: /koinon-service/view/usuario/index.php?erro=1");
        exit;
    }

    $controller = new UsuarioController();

    /*
     * DESATIVAR
     */
    if ($acao === "excluir") {

        $resultado = $controller->excluir($id);

        if ($resultado) {

            header(
                "Location: /koinon-service/view/usuario/index.php?sucesso=1"
            );

            exit;
        }

        header(
            "Location: /koinon-service/view/usuario/index.php?erro=1"
        );

        exit;
    }


    /*
     * REATIVAR
     */
    if ($acao === "reativar") {

        $resultado = $controller->reativar($id);

        if ($resultado) {

            header(
                "Location: /koinon-service/view/usuario/index.php?sucesso=1"
            );

            exit;
        }

        header(
            "Location: /koinon-service/view/usuario/index.php?erro=1"
        );

        exit;
    }
}