<?php

/*
 * ==========================================================
 * HISTÓRICO (ANDAMENTO) DAS OCORRÊNCIAS
 * ==========================================================
 *
 * O andamento é guardado na coluna ocorrencia.historico, em JSON,
 * sem criar nenhuma tabela nova. Cada evento possui:
 *
 *   data      - data/hora do evento (Y-m-d H:i:s)
 *   status    - status da ocorrência no momento do evento
 *   mensagem  - texto exibido ao morador
 *   perfil    - perfil de quem registrou (Morador, Sindico, ...)
 *
 * O nome de quem registrou também é guardado (campo "autor"),
 * mas para o morador a tela mostra apenas o perfil (Síndico,
 * Administração, Portaria), preservando a privacidade da equipe.
 */
class OcorrenciaHistorico
{
    public const ROTULOS_STATUS = [
        "Aberta"       => "Aberta",
        "Em_Andamento" => "Em andamento",
        "Resolvida"    => "Resolvida",
        "Cancelada"    => "Cancelada",
    ];

    public const ROTULOS_PERFIL = [
        "Morador"       => "Você",
        "Sindico"       => "Síndico",
        "Administrador" => "Administração",
        "Porteiro"      => "Portaria",
    ];

    public static function rotuloStatus(string $status): string
    {
        return self::ROTULOS_STATUS[$status] ?? $status;
    }

    public static function rotuloPerfil(?string $perfil): string
    {
        return self::ROTULOS_PERFIL[$perfil ?? ""] ?? "Condomínio";
    }

    /**
     * Acrescenta um evento ao histórico de uma ocorrência.
     * Nunca lança exceção: uma falha aqui não pode impedir o
     * cadastro/edição da própria ocorrência.
     */
    public static function registrar(
        PDO $pdo,
        int $ocorrenciaId,
        string $status,
        string $mensagem = "",
        ?string $autor = null,
        ?string $perfil = null
    ): void {

        if ($ocorrenciaId <= 0) {
            return;
        }

        try {

            $autor  ??= $_SESSION["usuario_nome"]   ?? null;
            $perfil ??= $_SESSION["usuario_perfil"] ?? null;

            $busca = $pdo->prepare(
                "SELECT historico FROM ocorrencia WHERE id = :id LIMIT 1"
            );
            $busca->bindValue(":id", $ocorrenciaId, PDO::PARAM_INT);
            $busca->execute();

            $linha = $busca->fetch(PDO::FETCH_ASSOC);

            if ($linha === false) {
                return;
            }

            $lista = self::decodificar($linha["historico"] ?? null);

            $lista[] = [
                "data"     => date("Y-m-d H:i:s"),
                "status"   => $status,
                "mensagem" => trim($mensagem),
                "autor"    => $autor,
                "perfil"   => $perfil,
            ];

            $grava = $pdo->prepare(
                "UPDATE ocorrencia SET historico = :historico WHERE id = :id"
            );
            $grava->bindValue(
                ":historico",
                json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                PDO::PARAM_STR
            );
            $grava->bindValue(":id", $ocorrenciaId, PDO::PARAM_INT);
            $grava->execute();

        } catch (Throwable $e) {
            // Histórico é complementar: ignora falhas silenciosamente.
        }
    }

    public static function decodificar(?string $json): array
    {
        if ($json === null || trim($json) === "") {
            return [];
        }

        $dados = json_decode($json, true);

        return is_array($dados) ? array_values($dados) : [];
    }

    /**
     * Devolve a linha do tempo pronta para exibir, do evento mais
     * antigo para o mais recente.
     *
     * Ocorrências antigas (criadas antes do histórico existir) não
     * têm eventos gravados; nesse caso a linha do tempo é montada a
     * partir da data de abertura, do status atual e da data de
     * fechamento, para que nenhuma ocorrência apareça vazia.
     */
    public static function montar(
        ?string $historicoJson,
        string $status,
        ?string $dataAbertura,
        ?string $dataFechamento
    ): array {

        $eventos = self::decodificar($historicoJson);

        if (empty($eventos)) {

            $eventos[] = [
                "data"     => $dataAbertura,
                "status"   => "Aberta",
                "mensagem" => "Ocorrência registrada e enviada à administração.",
                "perfil"   => "Morador",
            ];

            if ($status === "Em_Andamento") {
                $eventos[] = [
                    "data"     => null,
                    "status"   => "Em_Andamento",
                    "mensagem" => "A administração iniciou o atendimento.",
                    "perfil"   => "Administrador",
                ];
            } elseif ($status === "Resolvida" || $status === "Cancelada") {
                $eventos[] = [
                    "data"     => $dataFechamento,
                    "status"   => $status,
                    "mensagem" => $status === "Resolvida"
                        ? "Ocorrência resolvida."
                        : "Ocorrência cancelada.",
                    "perfil"   => "Administrador",
                ];
            }
        }

        foreach ($eventos as &$evento) {

            $evento["status_rotulo"] = self::rotuloStatus($evento["status"] ?? "");
            $evento["perfil_rotulo"] = self::rotuloPerfil($evento["perfil"] ?? null);

            $evento["data_formatada"] = !empty($evento["data"])
                ? date("d/m/Y H:i", strtotime($evento["data"]))
                : "";
        }
        unset($evento);

        return $eventos;
    }

    /**
     * Classe Bootstrap usada no selo de cada status.
     */
    public static function classeStatus(string $status): string
    {
        switch ($status) {
            case "Resolvida":
                return "success";
            case "Cancelada":
                return "danger";
            case "Em_Andamento":
                return "primary";
            default:
                return "warning";
        }
    }
}
