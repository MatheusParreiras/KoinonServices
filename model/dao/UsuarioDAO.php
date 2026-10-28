<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/UsuarioDTO.php";

class UsuarioDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }

    /*
     * BUSCAR USUÁRIO POR E-MAIL
     */
    public function buscarPorEmail(string $email): ?UsuarioDTO
    {
        $sql = "
            SELECT *
            FROM usuario
            WHERE email = :email
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":email",
            $email,
            PDO::PARAM_STR
        );

        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            return null;
        }

        return $this->montarDTO($dados);
    }

    /*
     * BUSCAR USUÁRIO POR CPF
     */
    public function buscarPorCpf(string $cpf): ?UsuarioDTO
    {
        $sql = "
            SELECT *
            FROM usuario
            WHERE cpf = :cpf
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":cpf",
            $cpf,
            PDO::PARAM_STR
        );

        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            return null;
        }

        return $this->montarDTO($dados);
    }

    /*
     * VERIFICA SE O E-MAIL JÁ ESTÁ CADASTRADO
     *
     * $idIgnorar é utilizado na edição.
     * Assim o usuário pode continuar usando
     * o próprio e-mail.
     */
    public function emailExiste(
        string $email,
        ?int $idIgnorar = null
    ): bool {

        if ($idIgnorar !== null) {

            $sql = "
                SELECT id
                FROM usuario
                WHERE email = :email
                AND id <> :id
                LIMIT 1
            ";

            $stmt = $this->conexao->prepare($sql);

            $stmt->bindValue(
                ":email",
                $email,
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":id",
                $idIgnorar,
                PDO::PARAM_INT
            );

        } else {

            $sql = "
                SELECT id
                FROM usuario
                WHERE email = :email
                LIMIT 1
            ";

            $stmt = $this->conexao->prepare($sql);

            $stmt->bindValue(
                ":email",
                $email,
                PDO::PARAM_STR
            );
        }

        $stmt->execute();

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /*
     * VERIFICA SE O CPF JÁ ESTÁ CADASTRADO
     *
     * $idIgnorar é utilizado na edição.
     */
    public function cpfExiste(
        string $cpf,
        ?int $idIgnorar = null
    ): bool {

        if ($idIgnorar !== null) {

            $sql = "
                SELECT id
                FROM usuario
                WHERE cpf = :cpf
                AND id <> :id
                LIMIT 1
            ";

            $stmt = $this->conexao->prepare($sql);

            $stmt->bindValue(
                ":cpf",
                $cpf,
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":id",
                $idIgnorar,
                PDO::PARAM_INT
            );

        } else {

            $sql = "
                SELECT id
                FROM usuario
                WHERE cpf = :cpf
                LIMIT 1
            ";

            $stmt = $this->conexao->prepare($sql);

            $stmt->bindValue(
                ":cpf",
                $cpf,
                PDO::PARAM_STR
            );
        }

        $stmt->execute();

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /*
     * LISTAR USUÁRIOS
     */
    public function listar(): array
    {
        $sql = "
            SELECT
                u.*,
                un.identificacao AS unidade
            FROM usuario u
            LEFT JOIN unidade un
                ON u.unidade_id = un.id
            ORDER BY u.id DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $usuarios = [];

        while ($dados = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $usuarios[] = $this->montarDTO($dados);
        }

        return $usuarios;
    }

    /*
     * BUSCAR USUÁRIO POR ID
     */
    public function buscarPorId(int $id): ?UsuarioDTO
    {
        $sql = "
            SELECT *
            FROM usuario
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            return null;
        }

        return $this->montarDTO($dados);
    }

    /*
     * CADASTRAR USUÁRIO
     */
    public function cadastrar(UsuarioDTO $usuario): bool
    {
        /*
         * Segurança adicional:
         * verifica CPF duplicado.
         */
        if ($this->cpfExiste($usuario->getCpf())) {
            return false;
        }

        /*
         * Segurança adicional:
         * verifica e-mail duplicado.
         */
        if ($this->emailExiste($usuario->getEmail())) {
            return false;
        }

        $sql = "
            INSERT INTO usuario (
                unidade_id,
                nome,
                cpf,
                email,
                telefone,
                senha_hash,
                perfil,
                biometria_hash,
                termo_lgpd,
                data_aceite_lgpd,
                ativo,
                senha_definida,
                primeiro_acesso
            )
            VALUES (
                :unidade_id,
                :nome,
                :cpf,
                :email,
                :telefone,
                :senha_hash,
                :perfil,
                :biometria_hash,
                :termo_lgpd,
                :data_aceite_lgpd,
                :ativo,
                0,
                1
            )
        ";

        try {

            $stmt = $this->conexao->prepare($sql);

            $unidadeId = $usuario->getUnidadeId();

            if ($unidadeId === null) {

                $stmt->bindValue(
                    ":unidade_id",
                    null,
                    PDO::PARAM_NULL
                );

            } else {

                $stmt->bindValue(
                    ":unidade_id",
                    $unidadeId,
                    PDO::PARAM_INT
                );
            }

            $stmt->bindValue(
                ":nome",
                $usuario->getNome(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":cpf",
                $usuario->getCpf(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":email",
                $usuario->getEmail(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":telefone",
                $usuario->getTelefone(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":senha_hash",
                $usuario->getSenhaHash(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":perfil",
                $usuario->getPerfil(),
                PDO::PARAM_STR
            );

            if ($usuario->getBiometriaHash() === null) {

                $stmt->bindValue(
                    ":biometria_hash",
                    null,
                    PDO::PARAM_NULL
                );

            } else {

                $stmt->bindValue(
                    ":biometria_hash",
                    $usuario->getBiometriaHash(),
                    PDO::PARAM_STR
                );
            }

            $stmt->bindValue(
                ":termo_lgpd",
                $usuario->getTermoLgpd(),
                PDO::PARAM_BOOL
            );

            if ($usuario->getDataAceiteLgpd() === null) {

                $stmt->bindValue(
                    ":data_aceite_lgpd",
                    null,
                    PDO::PARAM_NULL
                );

            } else {

                $stmt->bindValue(
                    ":data_aceite_lgpd",
                    $usuario->getDataAceiteLgpd(),
                    PDO::PARAM_STR
                );
            }

            $stmt->bindValue(
                ":ativo",
                $usuario->getAtivo(),
                PDO::PARAM_BOOL
            );

            return $stmt->execute();

        } catch (PDOException $e) {

            return false;
        }
    }

    /*
     * EDITAR USUÁRIO
     */
    public function editar(UsuarioDTO $usuario): bool
    {
        /*
         * Na edição, ignora o próprio ID.
         *
         * Exemplo:
         *
         * Usuário ID 5
         * CPF 12345678900
         *
         * O sistema encontra o CPF no banco,
         * mas percebe que ele pertence ao próprio ID 5.
         *
         * Portanto, permite.
         */

        if (
            $this->cpfExiste(
                $usuario->getCpf(),
                $usuario->getId()
            )
        ) {
            return false;
        }

        if (
            $this->emailExiste(
                $usuario->getEmail(),
                $usuario->getId()
            )
        ) {
            return false;
        }

        $sql = "
            UPDATE usuario
            SET
                unidade_id = :unidade_id,
                nome = :nome,
                cpf = :cpf,
                email = :email,
                telefone = :telefone,
                perfil = :perfil,
                biometria_hash = :biometria_hash,
                termo_lgpd = :termo_lgpd,
                data_aceite_lgpd = :data_aceite_lgpd,
                ativo = :ativo
            WHERE id = :id
        ";

        try {

            $stmt = $this->conexao->prepare($sql);

            $unidadeId = $usuario->getUnidadeId();

            if ($unidadeId === null) {

                $stmt->bindValue(
                    ":unidade_id",
                    null,
                    PDO::PARAM_NULL
                );

            } else {

                $stmt->bindValue(
                    ":unidade_id",
                    $unidadeId,
                    PDO::PARAM_INT
                );
            }

            $stmt->bindValue(
                ":nome",
                $usuario->getNome(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":cpf",
                $usuario->getCpf(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":email",
                $usuario->getEmail(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":telefone",
                $usuario->getTelefone(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":perfil",
                $usuario->getPerfil(),
                PDO::PARAM_STR
            );

            if ($usuario->getBiometriaHash() === null) {

                $stmt->bindValue(
                    ":biometria_hash",
                    null,
                    PDO::PARAM_NULL
                );

            } else {

                $stmt->bindValue(
                    ":biometria_hash",
                    $usuario->getBiometriaHash(),
                    PDO::PARAM_STR
                );
            }

            $stmt->bindValue(
                ":termo_lgpd",
                $usuario->getTermoLgpd(),
                PDO::PARAM_BOOL
            );

            if ($usuario->getDataAceiteLgpd() === null) {

                $stmt->bindValue(
                    ":data_aceite_lgpd",
                    null,
                    PDO::PARAM_NULL
                );

            } else {

                $stmt->bindValue(
                    ":data_aceite_lgpd",
                    $usuario->getDataAceiteLgpd(),
                    PDO::PARAM_STR
                );
            }

            $stmt->bindValue(
                ":ativo",
                $usuario->getAtivo(),
                PDO::PARAM_BOOL
            );

            $stmt->bindValue(
                ":id",
                $usuario->getId(),
                PDO::PARAM_INT
            );

            return $stmt->execute();

        } catch (PDOException $e) {

            return false;
        }
    }

    /*
     * DESATIVAR USUÁRIO
     *
     * NÃO exclui o registro do banco.
     * Apenas altera ativo para 0.
     */
    public function excluir(int $id): bool
    {
        $sql = "
            UPDATE usuario
            SET ativo = 0
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    /*
     * REATIVAR USUÁRIO
     */
    public function reativar(int $id): bool
    {
        $sql = "
            UPDATE usuario
            SET ativo = 1
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    /*
     * REGISTRAR ACEITE DA LGPD
     */
    public function aceitarLgpd(int $id): bool
    {

        $sql = "
            UPDATE usuario
            SET
                termo_lgpd = 1,
                data_aceite_lgpd = NOW()
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    /*
     * ALTERAR SENHA
     */
    public function alterarSenha(
        int $id,
        string $senhaHash
    ): bool {

        $sql = "
            UPDATE usuario
            SET
                senha_hash = :senha_hash,
                senha_definida = 1,
                primeiro_acesso = 0
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":senha_hash",
            $senhaHash,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    /*
     * TRANSFORMA OS DADOS DO BANCO EM DTO
     */
    private function montarDTO(array $dados): UsuarioDTO
    {
        $usuario = new UsuarioDTO();

        $usuario->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $usuario->setUnidadeId(
            isset($dados["unidade_id"]) &&
            $dados["unidade_id"] !== null
                ? (int) $dados["unidade_id"]
                : null
        );

        $usuario->setUnidadeIdentificacao(
            $dados["unidade"] ??
            $dados["unidade_identificacao"] ??
            null
        );

        $usuario->setNome(
            $dados["nome"] ?? ""
        );

        $usuario->setCpf(
            $dados["cpf"] ?? ""
        );

        $usuario->setEmail(
            $dados["email"] ?? ""
        );

        $usuario->setTelefone(
            $dados["telefone"] ?? ""
        );

        $usuario->setSenhaHash(
            $dados["senha_hash"] ?? ""
        );

        $usuario->setPerfil(
            $dados["perfil"] ?? ""
        );

        $usuario->setBiometriaHash(
            $dados["biometria_hash"] ?? null
        );

        $usuario->setTermoLgpd(
            (bool) ($dados["termo_lgpd"] ?? false)
        );

        $usuario->setDataAceiteLgpd(
            $dados["data_aceite_lgpd"] ?? null
        );

        $usuario->setAtivo(
            (bool) ($dados["ativo"] ?? true)
        );

        return $usuario;
    }
}