<?php

require_once __DIR__ . "/../model/dao/Conexao.php";

class ComprovanteDAO
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Conexao::conectar();
    }

    public function criarEncomenda(array $dados): bool
    {
        return $this->criar('Retirada_Encomenda', $dados['encomenda_id'] ?? null, null, $dados);
    }

    public function criarCobranca(array $dados): bool
    {
        return $this->criar('Pagamento_Cobranca', null, $dados['cobranca_id'] ?? null, $dados);
    }

    private function criar(string $tipo, ?int $encomendaId, ?int $cobrancaId, array $dados): bool
    {
        if ($encomendaId !== null) {
            $check = $this->pdo->prepare('SELECT id FROM comprovante WHERE encomenda_id = :id LIMIT 1');
            $check->execute([':id' => $encomendaId]);
            if ($check->fetchColumn()) return true;
        }

        if ($cobrancaId !== null) {
            $check = $this->pdo->prepare('SELECT id FROM comprovante WHERE cobranca_id = :id LIMIT 1');
            $check->execute([':id' => $cobrancaId]);
            if ($check->fetchColumn()) return true;
        }

        $numero = 'KNS-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $sql = 'INSERT INTO comprovante
                (numero_comprovante, tipo, encomenda_id, cobranca_id, unidade_id, usuario_id, data_emissao, dados_json)
                VALUES (:numero, :tipo, :encomenda_id, :cobranca_id, :unidade_id, :usuario_id, :data_emissao, :dados_json)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':numero', $numero);
        $stmt->bindValue(':tipo', $tipo);
        $stmt->bindValue(':encomenda_id', $encomendaId, $encomendaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':cobranca_id', $cobrancaId, $cobrancaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':unidade_id', $dados['unidade_id'] ?? null, ($dados['unidade_id'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':usuario_id', $dados['usuario_id'] ?? null, ($dados['usuario_id'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':data_emissao', $dados['data_emissao'] ?? date('Y-m-d H:i:s'));
        $stmt->bindValue(':dados_json', json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $stmt->execute();
    }

    public function listarDoMorador(int $usuarioId): array
    {
        $sql = "SELECT c.*
                FROM comprovante c
                INNER JOIN usuario u ON u.id = :usuario_id
                WHERE (c.usuario_id = :usuario_id2 OR c.unidade_id = u.unidade_id)
                ORDER BY c.data_emissao DESC, c.id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':usuario_id' => $usuarioId, ':usuario_id2' => $usuarioId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['dados'] = json_decode($row['dados_json'] ?? '{}', true) ?: [];
        }
        return $rows;
    }

    public function buscarParaMorador(int $id, int $usuarioId): ?array
    {
        $sql = "SELECT c.*
                FROM comprovante c
                INNER JOIN usuario u ON u.id = :usuario_id
                WHERE c.id = :id
                  AND (c.usuario_id = :usuario_id2 OR c.unidade_id = u.unidade_id)
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id, ':usuario_id' => $usuarioId, ':usuario_id2' => $usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['dados'] = json_decode($row['dados_json'] ?? '{}', true) ?: [];
        return $row;
    }

    public function garantirHistoricos(): void
    {
        // Backfill seguro para registros antigos que já eram pagos/retirados.
        $sql = "SELECT e.id, e.unidade_id, e.usuario_recebedor_id, e.descricao, e.codigo_rastreio,
                       e.data_recebimento, e.data_retirada, u.nome AS usuario_nome, un.identificacao
                FROM encomenda e
                LEFT JOIN usuario u ON u.id = e.usuario_recebedor_id
                INNER JOIN unidade un ON un.id = e.unidade_id
                LEFT JOIN comprovante c ON c.encomenda_id = e.id
                WHERE e.status = 'Entregue' AND c.id IS NULL";
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $this->criarEncomenda([
                'encomenda_id' => (int)$e['id'],
                'unidade_id' => (int)$e['unidade_id'],
                'usuario_id' => $e['usuario_recebedor_id'] !== null ? (int)$e['usuario_recebedor_id'] : null,
                'usuario_nome' => $e['usuario_nome'],
                'unidade_identificacao' => $e['identificacao'],
                'descricao' => $e['descricao'],
                'codigo_rastreio' => $e['codigo_rastreio'],
                'data_recebimento' => $e['data_recebimento'],
                'data_retirada' => $e['data_retirada'],
                'data_emissao' => $e['data_retirada'] ?: $e['data_recebimento'],
            ]);
        }

        $sql = "SELECT c.id, c.unidade_id, c.valor, c.descricao, c.vencimento, c.data_pagamento,
                       c.mes_referencia, c.tipo_cobranca, u.identificacao
                FROM cobranca c
                INNER JOIN unidade u ON u.id = c.unidade_id
                LEFT JOIN comprovante cp ON cp.cobranca_id = c.id
                WHERE c.status = 'Pago' AND cp.id IS NULL";
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $usuarioStmt = $this->pdo->prepare("SELECT id, nome FROM usuario WHERE unidade_id = :unidade AND perfil = 'Morador' AND ativo = 1 ORDER BY id LIMIT 1");
            $usuarioStmt->execute([':unidade' => (int)$c['unidade_id']]);
            $u = $usuarioStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $this->criarCobranca([
                'cobranca_id' => (int)$c['id'],
                'unidade_id' => (int)$c['unidade_id'],
                'usuario_id' => $u ? (int)$u['id'] : null,
                'usuario_nome' => $u['nome'] ?? null,
                'unidade_identificacao' => $c['identificacao'],
                'descricao' => $c['descricao'],
                'valor' => $c['valor'],
                'vencimento' => $c['vencimento'],
                'data_pagamento' => $c['data_pagamento'],
                'mes_referencia' => $c['mes_referencia'],
                'tipo_cobranca' => $c['tipo_cobranca'],
                'data_emissao' => $c['data_pagamento'] ?: date('Y-m-d H:i:s'),
            ]);
        }
    }
}
