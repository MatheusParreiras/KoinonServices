<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/MoradorController.php";
require_once __DIR__ . "/../../controller/LocalReservaController.php";
require_once __DIR__ . "/../../model/dao/MoradorDAO.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirMorador();

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);

$controller = new MoradorController();

$localController = new LocalReservaController();
$moradorDAO = new MoradorDAO();
$reservas = $moradorDAO->listarReservas($usuarioId);

$locais = $localController->listarAtivos();

$erro = "";

$espacoNome = "";
$dataInicio = "";
$dataFim = "";
$listaPresencaTexto = "";

/*
 * Guarda as configurações de duração dos locais.
 *
 * IMPORTANTE:
 * Usamos somente os métodos de duração.
 * O getNome() continua sendo usado da mesma forma
 * que já foi testado e funcionando.
 */
$configuracoesLocais = [];

foreach ($locais as $local) {

    $nomeLocal = (string) $local->getNome();

    $duracaoMin = 60;
    $duracaoMax = 60;

    /*
     * Proteção para evitar que a tela desapareça
     * caso algum método não exista no DTO.
     */
    if (method_exists($local, "getDuracaoMinutosMinima")) {

        $duracaoMin =
            (int) $local->getDuracaoMinutosMinima();
    }

    if (method_exists($local, "getDuracaoMinutosMaxima")) {

        $duracaoMax =
            (int) $local->getDuracaoMinutosMaxima();
    }

    /*
     * Valores de segurança.
     */
    if ($duracaoMin <= 0) {
        $duracaoMin = 60;
    }

    if ($duracaoMax <= 0) {
        $duracaoMax = $duracaoMin;
    }

    /*
     * Caso o máximo esteja menor que o mínimo,
     * corrigimos apenas para a exibição.
     */
    if ($duracaoMax < $duracaoMin) {

        $temporario = $duracaoMin;

        $duracaoMin = $duracaoMax;
        $duracaoMax = $temporario;
    }

    $configuracoesLocais[$nomeLocal] = [
        "min" => $duracaoMin,
        "max" => $duracaoMax,
        "fotos" => method_exists($local, "getFotosArray") ? $local->getFotosArray() : []
    ];
}


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $espacoNome =
        trim($_POST["espaco_nome"] ?? "");

    $dataInicio =
        trim($_POST["data_inicio"] ?? "");

    $dataFim =
        trim($_POST["data_fim"] ?? "");

    $listaPresencaTexto =
        (string) ($_POST["lista_presenca"] ?? "");

    $listaPresenca =
        ReservaDTO::listaDeTexto($listaPresencaTexto);


    if ($espacoNome === "") {

        $erro =
            "Selecione um local para realizar a reserva.";

    } elseif ($dataInicio === "") {

        $erro =
            "Informe a data e o horário de início.";

    } elseif ($dataFim === "") {

        $erro =
            "Informe a duração e o horário de início para calcular o término da reserva.";

    } else {

        $dataInicioMysql =
            str_replace("T", " ", $dataInicio);

        $dataFimMysql =
            str_replace("T", " ", $dataFim);


        if (strlen($dataInicioMysql) === 16) {

            $dataInicioMysql .= ":00";
        }

        if (strlen($dataFimMysql) === 16) {

            $dataFimMysql .= ":00";
        }


        try {

            /*
             * O backend continua sendo responsável
             * por validar:
             *
             * - capacidade
             * - duração mínima/máxima
             * - horários permitidos
             * - dias permitidos
             * - antecedência
             * - limite de reservas
             * - conflitos/overlap
             * - valor
             * - local ativo
             */

            $resultado =
                $controller->criarReserva(
                    $usuarioId,
                    $espacoNome,
                    $dataInicioMysql,
                    $dataFimMysql,
                    $listaPresenca
                );


            if ($resultado) {

                header(
                    "Location: nova_reserva.php?reserva=sucesso"
                );

                exit;
            }


            $erro =
                $controller->getUltimoErroReserva();


            if ($erro === "") {

                $erro =
                    "Não foi possível realizar a reserva. Verifique os dados informados.";
            }

        } catch (Throwable $e) {

            $erro =
                "Erro ao realizar a reserva: "
                . Erro::mensagem($e);
        }
    }
}

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Nova Reserva - Koinon Services</title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <style>

        body {
            background: #f5f6f8;
        }

        .container-reserva {
            max-width: 850px;
            margin: 40px auto;
        }

        .card-reserva {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
        }

        .titulo {
            font-weight: 700;
        }

        .subtitulo {
            color: #6c757d;
        }

        .local-selecionado {
            display: none;
            border-radius: 10px;
        }

        .info-duracao {
            display: none;
            border-radius: 10px;
        }

        .campo-fim {
            background-color: #f1f3f5;
            cursor: not-allowed;
        }

        .resumo-reserva {
            display: none;
            border-radius: 10px;
        }

        #galeriaLocal button img {
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
        }

        #galeriaLocal button:hover img {
            transform: scale(1.025);
            box-shadow: 0 .6rem 1.4rem rgba(0,0,0,.18) !important;
            filter: brightness(1.04);
        }

        /*
         * Área de disponibilidade.
         */
        .disponibilidade-reserva {
            display: none;
            border-radius: 10px;
        }

        .disponibilidade-reserva.verificando {
            display: block;
        }

        .disponibilidade-reserva.disponivel {
            display: block;
        }

        .disponibilidade-reserva.indisponivel {
            display: block;
        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>


<body>


<div class="container container-reserva">

    <div class="card card-reserva">

        <div class="card-body p-4 p-md-5">


            <div class="mb-4">

                <h2 class="titulo">
                    Nova Reserva
                </h2>

                <p class="subtitulo mb-0">
                    Solicite a reserva de uma área do condomínio.
                </p>

            </div>


            <?php if ($erro !== ""): ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>

            <?php if (($_GET["reserva"] ?? "") === "sucesso"): ?>
                <div class="alert alert-success" role="alert">Reserva realizada com sucesso. Sua solicitação aparece abaixo em <strong>Minhas reservas</strong>.</div>
            <?php elseif (($_GET["reserva"] ?? "") === "cancelada"): ?>
                <div class="alert alert-info" role="alert">Reserva cancelada com sucesso.</div>
            <?php elseif (($_GET["reserva"] ?? "") === "erro"): ?>
                <div class="alert alert-warning" role="alert">Não foi possível cancelar a reserva. Verifique o status e tente novamente.</div>
            <?php endif; ?>


            <?php if (count($locais) === 0): ?>

                <div class="alert alert-warning">

                    <strong>
                        Nenhum local disponível.
                    </strong>

                    <br>

                    Não existem áreas de reserva ativas no momento.

                </div>


            <?php else: ?>


                <form
                    method="POST"
                    action=""
                    id="formReserva"
                >


                    <!-- LOCAL -->

                    <div class="mb-4">

                        <label
                            for="espaco_nome"
                            class="form-label fw-semibold"
                        >
                            Local da reserva
                        </label>


                        <select
                            name="espaco_nome"
                            id="espaco_nome"
                            class="form-select form-select-lg"
                            required
                        >

                            <option value="">
                                Selecione um local
                            </option>


                            <?php foreach ($locais as $local): ?>

                                <?php

                                $nomeLocal =
                                    (string) $local->getNome();

                                $duracaoMin =
                                    $configuracoesLocais[$nomeLocal]["min"]
                                    ?? 60;

                                $duracaoMax =
                                    $configuracoesLocais[$nomeLocal]["max"]
                                    ?? $duracaoMin;

                                $capacidadeLocal =
                                    method_exists($local, "getCapacidade")
                                        ? (int) $local->getCapacidade()
                                        : 0;

                                ?>

                                <option
                                    value="<?= htmlspecialchars($nomeLocal) ?>"
                                    data-duracao-min="<?= (int) $duracaoMin ?>"
                                    data-duracao-max="<?= (int) $duracaoMax ?>"
                                    data-capacidade="<?= (int) $capacidadeLocal ?>"
                                    <?= $espacoNome === $nomeLocal ? "selected" : "" ?>
                                >

                                    <?= htmlspecialchars($nomeLocal) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>


                        <div class="form-text">

                            Selecione a área que deseja reservar.

                        </div>

                    </div>


                    <!-- LOCAL SELECIONADO -->

                    <div
                        id="localSelecionado"
                        class="alert alert-info local-selecionado mb-4"
                    >

                        <strong>
                            Local selecionado:
                        </strong>

                        <span id="nomeLocalSelecionado"></span>

                    </div>


                    <!-- ÁLBUM DO LOCAL -->

                    <div id="albumLocal" class="mb-4" style="display:none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <strong>📷 Fotos do local</strong>
                                    <small class="text-muted" id="contadorFotosLocal"></small>
                                </div>
                                <div id="galeriaLocal" class="row g-3"></div>
                                <div class="small text-muted mt-2">Clique em uma foto para abrir o álbum em tamanho maior.</div>
                            </div>
                        </div>
                    </div>


                    <!-- DURAÇÃO -->

                    <div
                        id="blocoDuracao"
                        class="mb-4"
                    >

                        <label
                            for="duracao"
                            class="form-label fw-semibold"
                        >
                            Duração da reserva
                        </label>


                        <select
                            id="duracao"
                            class="form-select"
                            disabled
                        >

                            <option value="">
                                Selecione o local primeiro
                            </option>

                        </select>


                        <div
                            id="infoDuracao"
                            class="alert alert-secondary info-duracao mt-2 mb-0"
                        >

                            <span id="textoDuracao"></span>

                        </div>


                        <div class="form-text">

                            A duração disponível depende da configuração
                            do local.

                        </div>

                    </div>


                    <!-- DATA INÍCIO E FIM -->

                    <div class="row">


                        <div class="col-md-6 mb-4">

                            <label
                                for="data_inicio"
                                class="form-label fw-semibold"
                            >
                                Data e horário de início
                            </label>


                            <input
                                type="datetime-local"
                                name="data_inicio"
                                id="data_inicio"
                                class="form-control"
                                value="<?= htmlspecialchars($dataInicio) ?>"
                                required
                            >


                            <div class="form-text">

                                Escolha quando deseja iniciar a utilização.

                            </div>

                        </div>


                        <div class="col-md-6 mb-4">

                            <label
                                for="data_fim"
                                class="form-label fw-semibold"
                            >
                                Data e horário de término
                            </label>


                            <input
                                type="datetime-local"
                                name="data_fim"
                                id="data_fim"
                                class="form-control campo-fim"
                                value="<?= htmlspecialchars($dataFim) ?>"
                                readonly
                                required
                            >


                            <div class="form-text">

                                O término será calculado automaticamente.

                            </div>

                        </div>

                    </div>


                    <!-- DISPONIBILIDADE -->

                    <div
                        id="disponibilidadeReserva"
                        class="alert disponibilidade-reserva mb-4"
                        role="alert"
                        aria-live="polite"
                    >

                        <span id="iconeDisponibilidade"></span>

                        <strong id="tituloDisponibilidade"></strong>

                        <div
                            id="textoDisponibilidade"
                            class="mt-1"
                        ></div>

                    </div>


                    <!-- RESUMO -->

                    <div
                        id="resumoReserva"
                        class="alert alert-success resumo-reserva mb-4"
                    >

                        <strong>
                            Resumo da reserva
                        </strong>

                        <br>

                        <span id="textoResumo"></span>

                    </div>


                    <!-- LISTA DE PRESENÇA -->

                    <div class="mb-4">

                        <label
                            for="lista_presenca"
                            class="form-label fw-semibold"
                        >
                            Lista de presença (convidados)
                        </label>

                        <div
                            id="avisoCapacidade"
                            class="alert alert-info py-2 small"
                        >
                            Selecione um local para ver quantas pessoas ele comporta.
                        </div>

                        <textarea
                            name="lista_presenca"
                            id="lista_presenca"
                            class="form-control"
                            rows="5"
                            placeholder="Um nome por linha&#10;Ex.: Maria Souza&#10;João Lima"
                        ><?= htmlspecialchars($listaPresencaTexto) ?></textarea>

                        <div class="form-text">
                            Informe o nome de cada convidado, um por linha.
                            A lista não pode ultrapassar a capacidade do local.
                            <strong id="contadorConvidados">0</strong>
                            <span id="limiteConvidados"></span>
                        </div>

                    </div>


                    <!-- AVISO -->

                    <div class="alert alert-secondary">

                        <strong>
                            Importante:
                        </strong>

                        <br>

                        O horário de término é calculado
                        automaticamente de acordo com a duração
                        permitida para o local.

                        <br><br>

                        Se já existir outra reserva
                        <strong>pendente ou confirmada</strong>
                        no mesmo local e horário, o sistema
                        não permitirá a nova reserva.

                        <br><br>

                        A solicitação será enviada como
                        <strong>Pendente</strong>.

                    </div>


                    <!-- BOTÕES -->

                    <div class="d-flex gap-2">

                        <a
                            href="index.php"
                            class="btn btn-secondary"
                        >
                            Voltar
                        </a>


                        <button
                            type="submit"
                            class="btn btn-primary"
                            id="btnSolicitar"
                        >
                            Solicitar reserva
                        </button>

                    </div>


                </form>


            <?php endif; ?>


        </div>

    </div>

</div>


    <div class="card card-reserva mt-4">
        <div class="card-body p-4 p-md-5">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h3 class="h4 mb-1">Minhas reservas</h3>
                    <p class="subtitulo mb-0">Consulte as reservas feitas pela sua conta e o status de cada solicitação.</p>
                </div>
                <span class="badge text-bg-primary"><?= count($reservas) ?> reserva(s)</span>
            </div>
            <?php if (!$reservas): ?>
                <div class="alert alert-light border mb-0">Você ainda não possui reservas cadastradas.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Local</th><th>Data</th><th>Horário</th><th>Convidados</th><th>Valor</th><th>Status</th><th>Ação</th></tr></thead>
                        <tbody>
                        <?php foreach ($reservas as $reserva): ?>
                            <?php
                                $status = (string)($reserva['status'] ?? 'Pendente');
                                $statusClass = match ($status) {
                                    'Confirmada' => 'text-bg-success',
                                    'Aguardando_Pagamento' => 'text-bg-warning',
                                    'Cancelada' => 'text-bg-danger',
                                    'Concluida' => 'text-bg-secondary',
                                    default => 'text-bg-warning'
                                };
                                $inicioTs = strtotime((string)($reserva['data_inicio'] ?? ''));
                                $fimTs = strtotime((string)($reserva['data_fim'] ?? ''));
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)($reserva['local_nome'] ?? $reserva['espaco_nome'] ?? '—')) ?></strong></td>
                                <td><?= $inicioTs ? date('d/m/Y', $inicioTs) : '—' ?></td>
                                <td><?= ($inicioTs && $fimTs) ? date('H:i', $inicioTs).' às '.date('H:i', $fimTs) : '—' ?></td>
                                <td>
                                    <?php $convidados = ReservaDTO::listaDeJson($reserva['lista_presenca'] ?? null); ?>
                                    <?php if ($convidados): ?>
                                        <details>
                                            <summary class="small"><?= count($convidados) ?> convidado(s)</summary>
                                            <ul class="small mb-0 ps-3"><?php foreach ($convidados as $c): ?><li><?= htmlspecialchars($c) ?></li><?php endforeach; ?></ul>
                                        </details>
                                    <?php else: ?>
                                        <span class="text-muted small">Sem lista</span>
                                    <?php endif; ?>
                                </td>
                                <td>R$ <?= number_format((float)($reserva['valor_taxa'] ?? 0), 2, ',', '.') ?></td>
                                <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($status === 'Aguardando_Pagamento' ? 'Aguardando pagamento' : $status) ?></span></td>
                                <td>
                                    <?php if ($status === 'Aguardando_Pagamento' && !empty($reserva['pagamento_id'])): ?>
                                        <a class="btn btn-sm btn-success" href="pagamento.php?id=<?= (int)$reserva['pagamento_id'] ?>">💳 Pagar agora</a>
                                    <?php endif; ?>
                                    <?php if (in_array($status, ['Pendente','Aguardando_Pagamento','Confirmada'], true)): ?>
                                        <a class="btn btn-sm btn-outline-danger" href="cancelar_reserva.php?id=<?= (int)$reserva['id'] ?>" onclick="return confirm('Deseja cancelar esta reserva?');">Cancelar</a>
                                    <?php else: ?>
                                        <span class="text-muted small">Sem ações</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>


<div class="modal fade" id="modalAlbumLocal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloAlbumLocal">Álbum do local</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body bg-dark text-center">
        <img id="fotoAlbumLocal" src="" alt="Foto do local" style="max-width:100%;max-height:72vh;object-fit:contain;border-radius:10px;">
        <div class="d-flex justify-content-center align-items-center gap-2 mt-3">
          <button type="button" class="btn btn-light" id="albumAnterior">← Anterior</button>
          <span class="badge text-bg-light" id="albumContador">1/1</span>
          <button type="button" class="btn btn-light" id="albumProxima">Próxima →</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>

const configuracoesFotosLocais = <?= json_encode(
    $configuracoesLocais,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

function atualizarAlbumLocal(nome) {
    const bloco = document.getElementById("albumLocal");
    const galeria = document.getElementById("galeriaLocal");
    const contador = document.getElementById("contadorFotosLocal");
    if (!bloco || !galeria) return;
    const dados = configuracoesFotosLocais[nome] || {};
    const fotos = Array.isArray(dados.fotos) ? dados.fotos : [];
    galeria.innerHTML = "";
    if (!fotos.length) { bloco.style.display = "none"; return; }
    fotos.forEach(function(foto, indice) {
        const col = document.createElement("div");
        col.className = "col-6 col-md-4 col-lg-3";
        const button = document.createElement("button");
        button.type = "button";
        button.className = "btn p-0 border-0 w-100";
        const img = document.createElement("img");
        img.src = "../../" + foto;
        img.alt = "Foto " + (indice + 1) + " do local";
        img.className = "w-100 rounded shadow-sm";
        img.style.height = "170px";
        img.style.objectFit = "cover";
        img.loading = "lazy";
        button.appendChild(img);
        button.addEventListener("click", function(){ abrirAlbumLocal(fotos, indice, nome); });
        col.appendChild(button);
        galeria.appendChild(col);
    });
    contador.textContent = fotos.length + (fotos.length === 1 ? " foto" : " fotos");
    bloco.style.display = "block";
}

let fotosAlbumAtual = [];
let indiceAlbumAtual = 0;
function abrirAlbumLocal(fotos, indice, nome) {
    fotosAlbumAtual = fotos;
    indiceAlbumAtual = indice;
    const modalEl = document.getElementById("modalAlbumLocal");
    const img = document.getElementById("fotoAlbumLocal");
    const titulo = document.getElementById("tituloAlbumLocal");
    titulo.textContent = "Álbum — " + nome;
    img.src = "../../" + fotos[indice];
    document.getElementById("albumContador").textContent = (indice + 1) + "/" + fotos.length;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}
function atualizarFotoAlbum() {
    if (!fotosAlbumAtual.length) return;
    const img = document.getElementById("fotoAlbumLocal");
    img.src = "../../" + fotosAlbumAtual[indiceAlbumAtual];
    document.getElementById("albumContador").textContent = (indiceAlbumAtual + 1) + "/" + fotosAlbumAtual.length;
}
document.getElementById("albumAnterior")?.addEventListener("click", function(){
    if (!fotosAlbumAtual.length) return;
    indiceAlbumAtual = (indiceAlbumAtual - 1 + fotosAlbumAtual.length) % fotosAlbumAtual.length;
    atualizarFotoAlbum();
});
document.getElementById("albumProxima")?.addEventListener("click", function(){
    if (!fotosAlbumAtual.length) return;
    indiceAlbumAtual = (indiceAlbumAtual + 1) % fotosAlbumAtual.length;
    atualizarFotoAlbum();
});


/*
 * ==========================================================
 * ELEMENTOS PRINCIPAIS
 * ==========================================================
 */

const selectLocal =
    document.getElementById("espaco_nome");

const selectDuracao =
    document.getElementById("duracao");

const dataInicio =
    document.getElementById("data_inicio");

const dataFim =
    document.getElementById("data_fim");

const localSelecionado =
    document.getElementById("localSelecionado");

const nomeLocalSelecionado =
    document.getElementById("nomeLocalSelecionado");

const infoDuracao =
    document.getElementById("infoDuracao");

const textoDuracao =
    document.getElementById("textoDuracao");

const resumoReserva =
    document.getElementById("resumoReserva");

const textoResumo =
    document.getElementById("textoResumo");

const disponibilidadeReserva =
    document.getElementById("disponibilidadeReserva");

const iconeDisponibilidade =
    document.getElementById("iconeDisponibilidade");

const tituloDisponibilidade =
    document.getElementById("tituloDisponibilidade");

const textoDisponibilidade =
    document.getElementById("textoDisponibilidade");

const formReserva =
    document.getElementById("formReserva");

const btnSolicitar =
    document.getElementById("btnSolicitar");


/*
 * Guarda o último resultado da verificação.
 *
 * null  = ainda não verificado
 * true  = disponível
 * false = indisponível
 */

let reservaDisponivel = null;


/*
 * Controle para evitar que uma resposta antiga
 * do servidor sobrescreva uma consulta mais nova.
 */

let numeroConsultaDisponibilidade = 0;


/*
 * ==========================================================
 * FORMATA DURAÇÃO
 * ==========================================================
 */

function formatarDuracao(minutos) {

    minutos = parseInt(minutos);

    if (minutos === 60) {
        return "1 hora";
    }

    if (minutos < 60) {
        return minutos + " minutos";
    }

    const horas =
        Math.floor(minutos / 60);

    const minutosRestantes =
        minutos % 60;


    if (minutosRestantes === 0) {

        if (horas === 1) {
            return "1 hora";
        }

        return horas + " horas";
    }


    if (horas === 1) {

        return (
            "1 hora e "
            + minutosRestantes
            + " minutos"
        );
    }


    return (
        horas
        + " horas e "
        + minutosRestantes
        + " minutos"
    );
}


/*
 * ==========================================================
 * FORMATA DATETIME-LOCAL
 * ==========================================================
 */

function formatarDatetimeLocal(data) {

    const ano =
        data.getFullYear();

    const mes =
        String(
            data.getMonth() + 1
        ).padStart(2, "0");

    const dia =
        String(
            data.getDate()
        ).padStart(2, "0");

    const hora =
        String(
            data.getHours()
        ).padStart(2, "0");

    const minuto =
        String(
            data.getMinutes()
        ).padStart(2, "0");


    return (
        ano
        + "-"
        + mes
        + "-"
        + dia
        + "T"
        + hora
        + ":"
        + minuto
    );
}


/*
 * ==========================================================
 * LIMPA DISPONIBILIDADE
 * ==========================================================
 */

function limparDisponibilidade() {

    reservaDisponivel = null;

    if (!disponibilidadeReserva) {
        return;
    }

    disponibilidadeReserva.className =
        "alert disponibilidade-reserva mb-4";

    disponibilidadeReserva.style.display =
        "none";

    if (iconeDisponibilidade) {
        iconeDisponibilidade.textContent = "";
    }

    if (tituloDisponibilidade) {
        tituloDisponibilidade.textContent = "";
    }

    if (textoDisponibilidade) {
        textoDisponibilidade.textContent = "";
    }
}


/*
 * ==========================================================
 * MOSTRA DISPONIBILIDADE
 * ==========================================================
 */

function mostrarDisponibilidade(
    disponivel,
    mensagem
) {

    reservaDisponivel =
        disponivel === true;


    if (!disponibilidadeReserva) {
        return;
    }


    if (disponivel) {

        disponibilidadeReserva.className =
            "alert alert-success disponibilidade-reserva mb-4";

        disponibilidadeReserva.style.display =
            "block";


        if (iconeDisponibilidade) {
            iconeDisponibilidade.textContent =
                "🟢 ";
        }


        if (tituloDisponibilidade) {
            tituloDisponibilidade.textContent =
                "Horário disponível";
        }


        if (textoDisponibilidade) {

            textoDisponibilidade.textContent =
                mensagem ||
                "Este horário está disponível para solicitação.";
        }

        return;
    }


    disponibilidadeReserva.className =
        "alert alert-danger disponibilidade-reserva mb-4";

    disponibilidadeReserva.style.display =
        "block";


    if (iconeDisponibilidade) {
        iconeDisponibilidade.textContent =
            "🔴 ";
    }


    if (tituloDisponibilidade) {
        tituloDisponibilidade.textContent =
            "Horário indisponível";
    }


    if (textoDisponibilidade) {

        textoDisponibilidade.textContent =
            mensagem ||
            "Já existe uma reserva para este período.";
    }
}


/*
 * ==========================================================
 * MOSTRA STATUS DE VERIFICAÇÃO
 * ==========================================================
 */

function mostrarVerificando() {

    reservaDisponivel = null;


    if (!disponibilidadeReserva) {
        return;
    }


    disponibilidadeReserva.className =
        "alert alert-warning disponibilidade-reserva mb-4";

    disponibilidadeReserva.style.display =
        "block";


    if (iconeDisponibilidade) {
        iconeDisponibilidade.textContent =
            "⏳ ";
    }


    if (tituloDisponibilidade) {
        tituloDisponibilidade.textContent =
            "Verificando disponibilidade...";
    }


    if (textoDisponibilidade) {
        textoDisponibilidade.textContent =
            "Aguarde enquanto verificamos este horário.";
    }
}


/*
 * ==========================================================
 * VERIFICA DISPONIBILIDADE VIA AJAX
 * ==========================================================
 */

async function verificarDisponibilidade() {

    /*
     * Se os elementos não existirem,
     * não executa nada.
     */

    if (
        !selectLocal ||
        !dataInicio ||
        !dataFim
    ) {
        return;
    }


    const espaco =
        selectLocal.value.trim();

    const inicio =
        dataInicio.value.trim();

    const fim =
        dataFim.value.trim();


    /*
     * Ainda não temos todos os dados.
     */

    if (
        espaco === ""
        ||
        inicio === ""
        ||
        fim === ""
    ) {

        limparDisponibilidade();

        return;
    }


    /*
     * Verifica se a data final realmente
     * é posterior à inicial.
     */

    const inicioData =
        new Date(inicio);

    const fimData =
        new Date(fim);


    if (
        isNaN(inicioData.getTime())
        ||
        isNaN(fimData.getTime())
    ) {

        limparDisponibilidade();

        return;
    }


    if (fimData <= inicioData) {

        limparDisponibilidade();

        return;
    }


    /*
     * Nova consulta.
     */

    numeroConsultaDisponibilidade++;

    const consultaAtual =
        numeroConsultaDisponibilidade;


    mostrarVerificando();


    if (btnSolicitar) {

        btnSolicitar.disabled =
            true;
    }


    try {

        const parametros =
            new URLSearchParams({
                espaco_nome: espaco,
                data_inicio: inicio,
                data_fim: fim
            });


        const resposta =
            await fetch(
                "verificar_disponibilidade.php?"
                + parametros.toString(),
                {
                    method: "GET",
                    headers: {
                        "Accept":
                            "application/json"
                    },
                    cache: "no-store"
                }
            );


        /*
         * Se outra consulta já foi iniciada,
         * ignoramos esta resposta.
         */

        if (
            consultaAtual !==
            numeroConsultaDisponibilidade
        ) {
            return;
        }


        if (!resposta.ok) {

            throw new Error(
                "Erro HTTP "
                + resposta.status
            );
        }


        const resultado =
            await resposta.json();


        if (
            !resultado
            ||
            resultado.sucesso !== true
        ) {

            throw new Error(
                resultado?.mensagem
                ||
                "Não foi possível verificar a disponibilidade."
            );
        }


        mostrarDisponibilidade(
            resultado.disponivel === true,
            resultado.mensagem
        );


        /*
         * Só libera o botão quando o horário
         * estiver disponível.
         */

        if (btnSolicitar) {

            btnSolicitar.disabled =
                resultado.disponivel !== true;
        }


    } catch (erro) {

        /*
         * Se outra consulta já foi iniciada,
         * não altera a tela.
         */

        if (
            consultaAtual !==
            numeroConsultaDisponibilidade
        ) {
            return;
        }


        reservaDisponivel = null;


        if (disponibilidadeReserva) {

            disponibilidadeReserva.className =
                "alert alert-warning disponibilidade-reserva mb-4";

            disponibilidadeReserva.style.display =
                "block";
        }


        if (iconeDisponibilidade) {

            iconeDisponibilidade.textContent =
                "⚠️ ";
        }


        if (tituloDisponibilidade) {

            tituloDisponibilidade.textContent =
                "Não foi possível verificar";
        }


        if (textoDisponibilidade) {

            textoDisponibilidade.textContent =
                "A verificação automática falhou. A validação definitiva será feita ao solicitar a reserva.";
        }


        /*
         * Em caso de erro na consulta AJAX,
         * permitimos o envio porque o backend
         * fará a validação definitiva.
         */

        if (btnSolicitar) {

            btnSolicitar.disabled =
                false;
        }
    }
}


/*
 * ==========================================================
 * ATUALIZA HORÁRIO DE TÉRMINO
 * ==========================================================
 */

function atualizarHorarioFim() {

    if (
        !dataInicio ||
        !dataFim ||
        !selectDuracao
    ) {
        return;
    }


    /*
     * Sempre que o horário muda,
     * a disponibilidade anterior deixa
     * de ser válida.
     */

    limparDisponibilidade();


    if (
        dataInicio.value === ""
        ||
        selectDuracao.value === ""
    ) {

        dataFim.value = "";

        if (resumoReserva) {
            resumoReserva.style.display = "none";
        }

        return;
    }


    const inicio =
        new Date(dataInicio.value);


    if (isNaN(inicio.getTime())) {

        dataFim.value = "";

        return;
    }


    const duracao =
        parseInt(selectDuracao.value);


    if (
        isNaN(duracao)
        ||
        duracao <= 0
    ) {

        dataFim.value = "";

        return;
    }


    /*
     * Soma a duração escolhida.
     */

    const fim =
        new Date(
            inicio.getTime()
            + (duracao * 60 * 1000)
        );


    dataFim.value =
        formatarDatetimeLocal(fim);


    /*
     * Atualiza o resumo.
     */

    if (
        resumoReserva &&
        textoResumo
    ) {

        const nome =
            selectLocal.options[
                selectLocal.selectedIndex
            ].text.trim();


        const inicioTexto =
            inicio.toLocaleString(
                "pt-BR",
                {
                    dateStyle: "short",
                    timeStyle: "short"
                }
            );


        const fimTexto =
            fim.toLocaleString(
                "pt-BR",
                {
                    dateStyle: "short",
                    timeStyle: "short"
                }
            );


        textoResumo.innerHTML =
            "<strong>"
            + nome
            + "</strong>"
            + "<br>"
            + inicioTexto
            + " até "
            + fimTexto
            + "<br>"
            + "Duração: "
            + formatarDuracao(duracao);


        resumoReserva.style.display =
            "block";
    }


    /*
     * Depois de calcular o término,
     * verifica automaticamente se o período
     * está disponível.
     */

    verificarDisponibilidade();
}


/*
 * ==========================================================
 * ATUALIZA DURAÇÕES
 * ==========================================================
 */

function atualizarDuracoes() {

    if (
        !selectLocal ||
        !selectDuracao
    ) {
        return;
    }


    /*
     * Sempre que o local mudar,
     * precisamos zerar a disponibilidade anterior.
     */

    limparDisponibilidade();


    const opcao =
        selectLocal.options[
            selectLocal.selectedIndex
        ];


    if (
        !opcao
        ||
        !opcao.value
    ) {

        selectDuracao.innerHTML =
            '<option value="">Selecione o local primeiro</option>';

        selectDuracao.disabled = true;

        if (infoDuracao) {
            infoDuracao.style.display = "none";
        }

        if (localSelecionado) {
            localSelecionado.style.display = "none";
        }

        if (dataFim) {
            dataFim.value = "";
        }

        if (resumoReserva) {
            resumoReserva.style.display = "none";
        }

        return;
    }


    const min =
        parseInt(
            opcao.dataset.duracaoMin
        );


    const max =
        parseInt(
            opcao.dataset.duracaoMax
        );


    if (
        isNaN(min)
        ||
        isNaN(max)
        ||
        min <= 0
        ||
        max <= 0
    ) {

        selectDuracao.innerHTML =
            '<option value="">Duração não configurada</option>';

        selectDuracao.disabled = true;

        return;
    }


    /*
     * Mostra o local selecionado.
     */

    if (
        localSelecionado &&
        nomeLocalSelecionado
    ) {

        nomeLocalSelecionado.textContent =
            opcao.text.trim();

        localSelecionado.style.display =
            "block";
    }

    atualizarAlbumLocal(opcao.value);


    /*
     * Limpa as opções anteriores.
     */

    selectDuracao.innerHTML = "";


    /*
     * Duração fixa.
     */

    if (min === max) {

        const option =
            document.createElement("option");

        option.value = min;

        option.textContent =
            formatarDuracao(min);

        option.selected = true;

        selectDuracao.appendChild(option);

        selectDuracao.disabled = false;


        if (
            infoDuracao &&
            textoDuracao
        ) {

            textoDuracao.innerHTML =
                "Este local possui duração fixa de <strong>"
                + formatarDuracao(min)
                + "</strong>.";

            infoDuracao.style.display =
                "block";
        }


        atualizarHorarioFim();

        return;
    }


    /*
     * Duração variável.
     */

    let duracaoAtual = min;


    while (duracaoAtual <= max) {

        const option =
            document.createElement("option");

        option.value =
            duracaoAtual;

        option.textContent =
            formatarDuracao(duracaoAtual);

        selectDuracao.appendChild(option);


        /*
         * Incremento de 30 minutos.
         */

        duracaoAtual += 30;
    }


    /*
     * Se o máximo não cair exatamente
     * em um intervalo de 30 minutos,
     * adicionamos o máximo.
     */

    if (
        (max - min) % 30 !== 0
    ) {

        const option =
            document.createElement("option");

        option.value =
            max;

        option.textContent =
            formatarDuracao(max);

        selectDuracao.appendChild(option);
    }


    selectDuracao.disabled = false;


    if (
        infoDuracao &&
        textoDuracao
    ) {

        textoDuracao.innerHTML =
            "Você pode escolher uma duração entre <strong>"
            + formatarDuracao(min)
            + "</strong> e <strong>"
            + formatarDuracao(max)
            + "</strong>.";

        infoDuracao.style.display =
            "block";
    }


    /*
     * Seleciona inicialmente o mínimo.
     */

    selectDuracao.value =
        String(min);


    atualizarHorarioFim();
}


/*
 * ==========================================================
 * EVENTO: LOCAL
 * ==========================================================
 */

if (selectLocal) {

    selectLocal.addEventListener(
        "change",
        function() {

            atualizarDuracoes();

        }
    );
}


/*
 * ==========================================================
 * EVENTO: DURAÇÃO
 * ==========================================================
 */

if (selectDuracao) {

    selectDuracao.addEventListener(
        "change",
        function() {

            atualizarHorarioFim();

        }
    );
}


/*
 * ==========================================================
 * EVENTO: DATA/HORA
 * ==========================================================
 */

if (dataInicio) {

    dataInicio.addEventListener(
        "change",
        function() {

            atualizarHorarioFim();

        }
    );


    dataInicio.addEventListener(
        "input",
        function() {

            atualizarHorarioFim();

        }
    );
}


/*
 * ==========================================================
 * INICIALIZAÇÃO
 * ==========================================================
 */

if (selectLocal) {

    atualizarDuracoes();

}


/*
 * ==========================================================
 * VALIDAÇÃO ANTES DO ENVIO
 * ==========================================================
 */

if (formReserva) {

    formReserva.addEventListener(
        "submit",
        function(event) {

            if (
                !selectLocal
                ||
                !selectLocal.value
            ) {

                event.preventDefault();

                alert(
                    "Selecione o local da reserva."
                );

                return;
            }


            if (
                !selectDuracao
                ||
                !selectDuracao.value
            ) {

                event.preventDefault();

                alert(
                    "Selecione a duração da reserva."
                );

                return;
            }


            if (
                !dataInicio
                ||
                !dataInicio.value
            ) {

                event.preventDefault();

                alert(
                    "Informe a data e o horário de início."
                );

                return;
            }


            if (
                !dataFim
                ||
                !dataFim.value
            ) {

                event.preventDefault();

                alert(
                    "Não foi possível calcular o horário de término."
                );

                return;
            }


            const inicio =
                new Date(dataInicio.value);

            const fim =
                new Date(dataFim.value);


            if (
                isNaN(inicio.getTime())
                ||
                isNaN(fim.getTime())
            ) {

                event.preventDefault();

                alert(
                    "Verifique a data e o horário informados."
                );

                return;
            }


            if (fim <= inicio) {

                event.preventDefault();

                alert(
                    "O horário de término deve ser posterior ao horário de início."
                );

                return;
            }


            /*
             * Se a consulta confirmou que o horário
             * está ocupado, não deixa enviar.
             */

            if (reservaDisponivel === false) {

                event.preventDefault();

                alert(
                    "Este horário está indisponível. Escolha outro horário."
                );

                return;
            }


            /*
             * Se ainda estiver verificando,
             * impede o envio para evitar uma ação
             * antes da resposta do AJAX.
             */

            if (reservaDisponivel === null) {

                event.preventDefault();

                alert(
                    "Aguarde a verificação da disponibilidade do horário."
                );

                verificarDisponibilidade();

                return;
            }

        }
    );
}

</script>
<script src="../../assets/js/script.js">
/* Capacidade do local e contador da lista de presença */
(function () {
    var sel = document.getElementById("espaco_nome");
    var area = document.getElementById("lista_presenca");
    var aviso = document.getElementById("avisoCapacidade");
    var cont = document.getElementById("contadorConvidados");
    var lim = document.getElementById("limiteConvidados");
    if (!sel || !area) { return; }
    function capacidade() {
        var o = sel.options[sel.selectedIndex];
        return o ? parseInt(o.getAttribute("data-capacidade") || "0", 10) : 0;
    }
    function atualizar() {
        var cap = capacidade();
        var nomes = area.value.split(/[\r\n;]+/).map(function (n) { return n.trim(); }).filter(Boolean);
        cont.textContent = nomes.length;
        if (cap > 0) {
            aviso.textContent = "Este local comporta até " + cap + " pessoa(s). A lista de presença pode ter no máximo " + cap + " nome(s).";
            lim.textContent = "de " + cap + " permitido(s).";
            aviso.className = "alert py-2 small " + (nomes.length > cap ? "alert-danger" : "alert-info");
        } else {
            aviso.textContent = "Selecione um local para ver quantas pessoas ele comporta.";
            lim.textContent = "";
            aviso.className = "alert alert-info py-2 small";
        }
    }
    sel.addEventListener("change", atualizar);
    area.addEventListener("input", atualizar);
    atualizar();
})();
</script>

</body>

</html>