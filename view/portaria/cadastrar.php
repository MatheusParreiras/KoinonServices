<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirPortaria();

require_once __DIR__ . "/../../controller/RegistroAcessoController.php";
require_once __DIR__ . "/../../controller/VisitanteController.php";
require_once __DIR__ . "/../../controller/UsuarioController.php";

$registroController = new RegistroAcessoController();
$visitanteController = new VisitanteController();
$usuarioController = new UsuarioController();

$visitantes = $visitanteController->listar();
$usuariosAutorizadores = array_filter($usuarioController->listar(), function ($usuario) {
    return (int) $usuario->getAtivo() === 1 && in_array($usuario->getPerfil(), ["Morador", "Administrador", "Sindico", "Porteiro"], true);
});

$mensagem = "";
$tipoMensagem = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $visitanteId = isset($_POST["visitante_id"])
        ? (int) $_POST["visitante_id"]
        : 0;

    $direcao = $_POST["direcao"] ?? "";
    $metodoLeitura = $_POST["metodo_leitura"] ?? "";
    $dataHora = $_POST["data_hora"] ?? "";
    $observacao = trim($_POST["observacao"] ?? "");
    $autorizadoPorUsuarioId = isset($_POST["autorizado_por_usuario_id"]) && $_POST["autorizado_por_usuario_id"] !== ""
        ? (int) $_POST["autorizado_por_usuario_id"]
        : null;

    $erros = [];

    if ($visitanteId <= 0) {
        $erros[] = "Selecione um visitante.";
    }

    $direcoesPermitidas = [
        "Entrada",
        "Saida"
    ];

    if (!in_array($direcao, $direcoesPermitidas, true)) {
        $erros[] = "Selecione uma direção válida.";
    }

    $metodosPermitidos = [
        "Manual",
        "QR_Code",
        "Facial",
        "Placa_LPR",
        "Tag_RFID"
    ];

    if (!in_array($metodoLeitura, $metodosPermitidos, true)) {
        $erros[] = "Selecione um método de leitura válido.";
    }

    if (empty($dataHora)) {
        $erros[] = "Informe a data e hora do acesso.";
    }

    if ($direcao === "Entrada" && $autorizadoPorUsuarioId === null) {
        $erros[] = "Selecione quem autorizou a entrada.";
    }

    if (empty($erros)) {

        /*
         * O campo datetime-local envia:
         * 2026-08-24T20:50
         *
         * O MySQL utiliza:
         * 2026-08-24 20:50:00
         */

        $dataHora = str_replace("T", " ", $dataHora);

        if (strlen($dataHora) === 16) {
            $dataHora .= ":00";
        }

        $registro = new RegistroAcessoDTO();

        $registro->setVisitanteId($visitanteId);
        $registro->setAutorizadoPorUsuarioId($autorizadoPorUsuarioId);

        /*
         * Se houver usuário logado, registramos
         * quem realizou o lançamento.
         */

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (isset($_SESSION["usuario_id"])) {
            $registro->setUsuarioId(
                (int) $_SESSION["usuario_id"]
            );
        }

        $registro->setDataHora($dataHora);
        $registro->setDirecao($direcao);
        $registro->setMetodoLeitura($metodoLeitura);

        if ($observacao !== "") {
            $registro->setObservacao($observacao);
        } else {
            $registro->setObservacao(null);
        }

        try {

            if ($registroController->cadastrar($registro)) {

                header(
                    "Location: index.php?sucesso=1"
                );

                exit;

            } else {

                $mensagem = "Não foi possível registrar o acesso.";
                $tipoMensagem = "danger";
            }

        } catch (Exception $e) {

            $mensagem = "Erro ao registrar o acesso: "
                . $e->getMessage();

            $tipoMensagem = "danger";
        }

    } else {

        $mensagem = implode("<br>", $erros);
        $tipoMensagem = "danger";
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

    <title>Registrar Acesso - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <div class="row justify-content-center">

        <div class="col-md-8">

            <div class="card shadow">

                <div class="card-header bg-primary text-white">

                    <h4 class="mb-0">
                        🛂 Registrar Acesso
                    </h4>

                </div>


                <div class="card-body">

                    <?php if (!empty($mensagem)): ?>

                        <div class="alert alert-<?= $tipoMensagem ?>">

                            <?= $mensagem ?>

                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        action=""
                    >

                        <!-- VISITANTE -->

                        <div class="mb-3">

                            <label
                                for="visitante_id"
                                class="form-label"
                            >
                                Visitante *
                            </label>

                            <input type="search" id="visitante_busca" class="form-control" list="visitantes_lista" placeholder="🔎 Digite o nome ou documento..." autocomplete="off" required>
                            <input type="hidden" name="visitante_id" id="visitante_id" value="<?= htmlspecialchars($_POST['visitante_id'] ?? '') ?>">
                            <datalist id="visitantes_lista">
                                <?php foreach ($visitantes as $visitante): ?>
                                    <option value="<?= htmlspecialchars($visitante->getNome() . ' — ' . Cpf::formatar($visitante->getDocumento())) ?>" data-id="<?= (int)$visitante->getId() ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text">Digite qualquer parte do nome ou documento e escolha o resultado. A lista filtra enquanto você escreve.</div>

                        </div>


                        <!-- DIREÇÃO -->

                        <div class="mb-3">

                            <label
                                for="direcao"
                                class="form-label"
                            >
                                Direção *
                            </label>

                            <select
                                name="direcao"
                                id="direcao"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Selecione
                                </option>

                                <option
                                    value="Entrada"
                                    <?= (
                                        ($_POST["direcao"] ?? "") === "Entrada"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Entrada
                                </option>

                                <option
                                    value="Saida"
                                    <?= (
                                        ($_POST["direcao"] ?? "") === "Saida"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Saída
                                </option>

                            </select>

                        </div>


                        <!-- MÉTODO -->

                        <div class="mb-3">

                            <label
                                for="metodo_leitura"
                                class="form-label"
                            >
                                Método de leitura *
                            </label>

                            <select
                                name="metodo_leitura"
                                id="metodo_leitura"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Selecione
                                </option>

                                <option
                                    value="Manual"
                                    <?= (
                                        ($_POST["metodo_leitura"] ?? "") === "Manual"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Manual
                                </option>

                                <option
                                    value="QR_Code"
                                    <?= (
                                        ($_POST["metodo_leitura"] ?? "") === "QR_Code"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    QR Code
                                </option>

                                <option
                                    value="Facial"
                                    <?= (
                                        ($_POST["metodo_leitura"] ?? "") === "Facial"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Reconhecimento Facial
                                </option>

                                <option
                                    value="Placa_LPR"
                                    <?= (
                                        ($_POST["metodo_leitura"] ?? "") === "Placa_LPR"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Placa LPR
                                </option>

                                <option
                                    value="Tag_RFID"
                                    <?= (
                                        ($_POST["metodo_leitura"] ?? "") === "Tag_RFID"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Tag RFID
                                </option>

                            </select>

                        </div>


                        <!-- DATA E HORA -->

                        <div class="mb-3">

                            <label
                                for="data_hora"
                                class="form-label"
                            >
                                Data e hora do acesso *
                            </label>

                            <input
                                type="datetime-local"
                                name="data_hora"
                                id="data_hora"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $_POST["data_hora"] ?? date("Y-m-d\TH:i")
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- AUTORIZADOR DO VISITANTE -->

                        <div class="mb-3">

                            <label for="autorizado_por_usuario_id" class="form-label">
                                Quem autorizou
                            </label>

                            <select name="autorizado_por_usuario_id" id="autorizado_por_usuario_id" class="form-select">
                                <option value="">Selecione quem autorizou...</option>
                                <?php foreach ($usuariosAutorizadores as $a): ?>
                                    <option value="<?= $a->getId() ?>" <?= ((string)($_POST['autorizado_por_usuario_id'] ?? '') === (string)$a->getId()) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a->getNome()) ?> — <?= htmlspecialchars($a->getPerfil()) ?> — <?= htmlspecialchars($a->getEmail()) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="form-text">
                                Obrigatório para entrada de visitante. Na saída, pode ficar sem seleção.
                            </div>

                        </div>


                        <!-- OBSERVAÇÃO -->

                        <div class="mb-4">

                            <label
                                for="observacao"
                                class="form-label"
                            >
                                Observação
                            </label>

                            <textarea
                                name="observacao"
                                id="observacao"
                                class="form-control"
                                rows="4"
                                placeholder="Ex.: Visitante autorizado pelo morador."
                            ><?= htmlspecialchars(
                                $_POST["observacao"] ?? ""
                            ) ?></textarea>

                        </div>


                        <!-- BOTÕES -->

                        <div class="d-flex justify-content-between">

                            <a
                                href="index.php"
                                class="btn btn-secondary"
                            >
                                Voltar
                            </a>

                            <button
                                type="submit"
                                class="btn btn-success"
                            >
                                Registrar Acesso
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>

</div>
<script src="../../assets/js/script.js"></script>

<script>
(function(){
 const busca=document.getElementById('visitante_busca');
 const hidden=document.getElementById('visitante_id');
 const opts=[...document.querySelectorAll('#visitantes_lista option')].map(o=>({id:o.dataset.id,label:o.value}));
 function sync(){const val=(busca.value||'').trim().toLowerCase(); const found=opts.find(x=>x.label.toLowerCase()===val); hidden.value=found?found.id:'';}
 busca.addEventListener('input',sync); busca.addEventListener('change',sync);
})();
</script>
</body>

</html>