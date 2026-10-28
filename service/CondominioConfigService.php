<?php

require_once __DIR__ . "/../model/dao/Conexao.php";

/**
 * Regras persistidas no MariaDB. Não cria tabelas nem arquivos auxiliares.
 */
class CondominioConfigService
{
    public static function vagasUnidade(int $id): int
    {
        if ($id <= 0) return 0;
        $pdo = Conexao::conectar();
        $stmt = $pdo->prepare("SELECT quantidade_vagas FROM unidade WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $id]);
        $v = $stmt->fetchColumn();
        return max(0, min(2, (int)($v ?? 0)));
    }

    public static function salvarVagasUnidade(int $id, int $v): void
    {
        $pdo = Conexao::conectar();
        $stmt = $pdo->prepare("UPDATE unidade SET quantidade_vagas = :v WHERE id = :id");
        $stmt->execute([":v" => max(0, min(2, $v)), ":id" => $id]);
    }

    public static function statusVeiculo(int $id): string
    {
        if ($id <= 0) return "Pendente";
        $pdo = Conexao::conectar();
        $stmt = $pdo->prepare("SELECT status_aprovacao FROM veiculo WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $id]);
        $status = (string)($stmt->fetchColumn() ?: "Pendente");
        return in_array($status, ["Pendente", "Aprovado", "Rejeitado"], true) ? $status : "Pendente";
    }

    public static function salvarStatusVeiculo(int $id, string $status): void
    {
        if (!in_array($status, ["Pendente", "Aprovado", "Rejeitado"], true)) {
            throw new InvalidArgumentException("Status de aprovação de veículo inválido.");
        }
        $pdo = Conexao::conectar();
        $stmt = $pdo->prepare("UPDATE veiculo SET status_aprovacao = :status WHERE id = :id");
        $stmt->execute([":status" => $status, ":id" => $id]);
    }

    public static function removerVeiculo(int $id): void
    {
        // O status acompanha o próprio registro do veículo; não há arquivo externo para remover.
    }
}
