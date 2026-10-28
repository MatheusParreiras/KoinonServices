<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/LocalReservaController.php";
require_once __DIR__ . "/../../model/dto/LocalReservaDTO.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirGestao();

$controller =
    new LocalReservaController();


$id =
    isset($_GET["id"]) &&
    is_numeric($_GET["id"])
        ? (int) $_GET["id"]
        : 0;


if ($id <= 0) {

    header(
        "Location: locais.php?erro=local_invalido"
    );

    exit;
}


$local =
    $controller->buscarPorId(
        $id
    );


if (!$local) {

    header(
        "Location: locais.php?erro=local_nao_encontrado"
    );

    exit;
}


$erro = "";


function normalizarDecimal(
    string $valor
): string {

    $valor = trim($valor);

    if ($valor === "") {
        return "";
    }

    $valor = str_replace(
        ["R$", " "],
        "",
        $valor
    );

    if (str_contains($valor, ",")) {
        $valor = str_replace(
            ".",
            "",
            $valor
        );

        $valor = str_replace(
            ",",
            ".",
            $valor
        );
    }

    return $valor;
}


/*
 * ==========================================================
 * DADOS INICIAIS
 * ==========================================================
 */

$dados = [

    "nome"
        => $local->getNome(),

    "descricao"
        => $local->getDescricao(),

    "capacidade"
        => (string)
            $local->getCapacidade(),

    "valor"
        => number_format(
            $local->getValor(),
            2,
            ",",
            "."
        ),

    "gratuito"
        => $local->getGratuito(),

    "duracao_minima_horas"
        => (string) (
            $local->getDuracaoMinutosMinima()
            / 60
        ),

    "duracao_maxima_horas"
        => (string) (
            $local->getDuracaoMinutosMaxima()
            / 60
        ),

    "horario_inicio"
        => substr(
            $local->getHorarioInicio(),
            0,
            5
        ),

    "horario_fim"
        => substr(
            $local->getHorarioFim(),
            0,
            5
        ),

    "antecedencia_min_dias"
        => (string)
            $local->getAntecedenciaMinDias(),

    "antecedencia_max_dias"
        => (string)
            $local->getAntecedenciaMaxDias(),

    "limite_reservas_unidade"
        => (string)
            $local->getLimiteReservasUnidade(),

    "periodo_limite"
        => $local->getPeriodoLimite(),

    "domingo"
        => $local->getDomingo(),

    "segunda"
        => $local->getSegunda(),

    "terca"
        => $local->getTerca(),

    "quarta"
        => $local->getQuarta(),

    "quinta"
        => $local->getQuinta(),

    "sexta"
        => $local->getSexta(),

    "sabado"
        => $local->getSabado(),

    "ativo"
        => $local->getAtivo()

];


/*
 * ==========================================================
 * POST
 * ==========================================================
 */

if (
    $_SERVER["REQUEST_METHOD"]
    === "POST"
) {

    $dados["nome"] =
        trim(
            $_POST["nome"]
            ?? ""
        );

    $dados["descricao"] =
        trim(
            $_POST["descricao"]
            ?? ""
        );

    $dados["capacidade"] =
        trim(
            $_POST["capacidade"]
            ?? ""
        );

    $dados["valor"] =
        trim(
            $_POST["valor"]
            ?? "0"
        );

    $dados["gratuito"] =
        isset(
            $_POST["gratuito"]
        );

    $dados[
        "duracao_minima_horas"
    ] =
        trim(
            $_POST[
                "duracao_minima_horas"
            ]
            ?? ""
        );

    $dados[
        "duracao_maxima_horas"
    ] =
        trim(
            $_POST[
                "duracao_maxima_horas"
            ]
            ?? ""
        );

    $dados["horario_inicio"] =
        trim(
            $_POST["horario_inicio"]
            ?? ""
        );

    $dados["horario_fim"] =
        trim(
            $_POST["horario_fim"]
            ?? ""
        );

    $dados[
        "antecedencia_min_dias"
    ] =
        trim(
            $_POST[
                "antecedencia_min_dias"
            ]
            ?? ""
        );

    $dados[
        "antecedencia_max_dias"
    ] =
        trim(
            $_POST[
                "antecedencia_max_dias"
            ]
            ?? ""
        );

    $dados[
        "limite_reservas_unidade"
    ] =
        trim(
            $_POST[
                "limite_reservas_unidade"
            ]
            ?? ""
        );

    $dados["periodo_limite"] =
        $_POST[
            "periodo_limite"
        ]
        ?? "Dia";


    /*
     * DIAS
     */

    $dados["domingo"] =
        isset(
            $_POST["domingo"]
        );

    $dados["segunda"] =
        isset(
            $_POST["segunda"]
        );

    $dados["terca"] =
        isset(
            $_POST["terca"]
        );

    $dados["quarta"] =
        isset(
            $_POST["quarta"]
        );

    $dados["quinta"] =
        isset(
            $_POST["quinta"]
        );

    $dados["sexta"] =
        isset(
            $_POST["sexta"]
        );

    $dados["sabado"] =
        isset(
            $_POST["sabado"]
        );


    /*
     * STATUS
     */

    $dados["ativo"] =
        isset(
            $_POST["ativo"]
        );


    /*
     * ==========================================================
     * VALIDAÇÕES
     * ==========================================================
     */

    $capacidade =
        filter_var(
            $dados["capacidade"],
            FILTER_VALIDATE_INT
        );


    $duracaoMinHoras =
        filter_var(
            normalizarDecimal(
                (string) $dados[
                    "duracao_minima_horas"
                ]
            ),
            FILTER_VALIDATE_FLOAT
        );


    $duracaoMaxHoras =
        filter_var(
            normalizarDecimal(
                (string) $dados[
                    "duracao_maxima_horas"
                ]
            ),
            FILTER_VALIDATE_FLOAT
        );


    $antecedenciaMin =
        filter_var(
            $dados[
                "antecedencia_min_dias"
            ],
            FILTER_VALIDATE_INT
        );


    $antecedenciaMax =
        filter_var(
            $dados[
                "antecedencia_max_dias"
            ],
            FILTER_VALIDATE_INT
        );


    $limite =
        filter_var(
            $dados[
                "limite_reservas_unidade"
            ],
            FILTER_VALIDATE_INT
        );


    /*
     * VALOR
     */

    $valorTexto =
        normalizarDecimal(
            (string) $dados["valor"]
        );

    $valor =
        is_numeric(
            $valorTexto
        )
            ? (float) $valorTexto
            : -1;


    /*
     * ==========================================================
     * VALIDAÇÃO
     * ==========================================================
     */

    if (
        $dados["nome"] === ""
    ) {

        $erro =
            "Informe o nome do local.";

    } elseif (
        $controller->nomeExiste(
            $dados["nome"],
            $id
        )
    ) {

        $erro =
            "Já existe outro local de reserva com este nome.";

    } elseif (
        $capacidade === false ||
        $capacidade < 1
    ) {

        $erro =
            "A capacidade deve ser de pelo menos 1 pessoa.";

    } elseif (
        $valor < 0
    ) {

        $erro =
            "Informe um valor válido para a reserva.";

    } elseif (
        $duracaoMinHoras === false ||
        $duracaoMinHoras <= 0
    ) {

        $erro =
            "Informe uma duração mínima válida.";

    } elseif (
        $duracaoMaxHoras === false ||
        $duracaoMaxHoras <= 0
    ) {

        $erro =
            "Informe uma duração máxima válida.";

    } elseif (
        $duracaoMaxHoras <
        $duracaoMinHoras
    ) {

        $erro =
            "A duração máxima não pode ser menor que a duração mínima.";

    } elseif (
        $dados["horario_inicio"]
        === "" ||
        $dados["horario_fim"]
        === ""
    ) {

        $erro =
            "Informe o horário de funcionamento do local.";

    } elseif (
        $dados["horario_fim"]
        <=
        $dados["horario_inicio"]
    ) {

        $erro =
            "O horário final deve ser posterior ao horário inicial.";

    } elseif (
        $antecedenciaMin === false ||
        $antecedenciaMin < 0
    ) {

        $erro =
            "A antecedência mínima deve ser igual ou maior que zero.";

    } elseif (
        $antecedenciaMax === false ||
        $antecedenciaMax < 0
    ) {

        $erro =
            "A antecedência máxima deve ser igual ou maior que zero.";

    } elseif (
        $antecedenciaMax <
        $antecedenciaMin
    ) {

        $erro =
            "A antecedência máxima não pode ser menor que a mínima.";

    } elseif (
        $limite === false ||
        $limite < 1
    ) {

        $erro =
            "O limite de reservas por unidade deve ser de pelo menos 1.";

    } elseif (
        !in_array(
            $dados["periodo_limite"],
            [
                "Dia",
                "Mes",
                "Total"
            ],
            true
        )
    ) {

        $erro =
            "Período do limite de reservas inválido.";

    } elseif (
        !$dados["domingo"] &&
        !$dados["segunda"] &&
        !$dados["terca"] &&
        !$dados["quarta"] &&
        !$dados["quinta"] &&
        !$dados["sexta"] &&
        !$dados["sabado"]
    ) {

        $erro =
            "Selecione pelo menos um dia da semana permitido.";

    } else {


        /*
         * Gratuito = valor zero.
         */

        if (
            $dados["gratuito"]
        ) {

            $valor =
                0.00;
        }


        /*
         * ======================================================
         * ATUALIZA DTO
         * ======================================================
         */

        $local->setNome(
            $dados["nome"]
        );

        $local->setDescricao(
            $dados["descricao"]
        );

        $local->setCapacidade(
            (int) $capacidade
        );

        $local->setValor(
            $valor
        );

        $local->setGratuito(
            $dados["gratuito"]
        );


        $local->setDuracaoMinutosMinima(
            (int) round(
                $duracaoMinHoras * 60
            )
        );


        $local->setDuracaoMinutosMaxima(
            (int) round(
                $duracaoMaxHoras * 60
            )
        );


        $local->setHorarioInicio(
            $dados["horario_inicio"]
            .
            (
                strlen(
                    $dados["horario_inicio"]
                ) === 5
                    ? ":00"
                    : ""
            )
        );


        $local->setHorarioFim(
            $dados["horario_fim"]
            .
            (
                strlen(
                    $dados["horario_fim"]
                ) === 5
                    ? ":00"
                    : ""
            )
        );


        $local->setAntecedenciaMinDias(
            (int) $antecedenciaMin
        );


        $local->setAntecedenciaMaxDias(
            (int) $antecedenciaMax
        );


        $local->setLimiteReservasUnidade(
            (int) $limite
        );


        $local->setPeriodoLimite(
            $dados["periodo_limite"]
        );


        /*
         * Dias
         */

        $local->setDomingo(
            $dados["domingo"]
        );

        $local->setSegunda(
            $dados["segunda"]
        );

        $local->setTerca(
            $dados["terca"]
        );

        $local->setQuarta(
            $dados["quarta"]
        );

        $local->setQuinta(
            $dados["quinta"]
        );

        $local->setSexta(
            $dados["sexta"]
        );

        $local->setSabado(
            $dados["sabado"]
        );


        /*
         * Ativo
         */

        $local->setAtivo(
            $dados["ativo"]
        );


        /*
         * ======================================================
         * SALVAR
         * ======================================================
         */

        try {

            if (
                $controller->editar(
                    $local
                )
            ) {

                header(
                    "Location: locais.php?sucesso=2"
                );

                exit;
            }


            $erro =
                "Não foi possível atualizar o local de reserva.";

        } catch (
            Throwable $e
        ) {

            $erro =
                "Erro ao atualizar o local: "
                .
                Erro::mensagem($e);
        }
    }
}


/*
 * ==========================================================
 * CHECKBOX
 * ==========================================================
 */

function marcado(
    array $dados,
    string $campo
): string {

    return !empty(
        $dados[$campo]
    )
        ? "checked"
        : "";
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

    <title>
        Editar Local de Reserva - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>


<body class="bg-light">


<div class="container py-4">


    <div class="row justify-content-center">


        <div class="col-xl-9">


            <div class="card shadow">


                <div class="card-header bg-warning">

                    <h4 class="mb-0">

                        ✏️ Editar Local de Reserva

                    </h4>

                </div>


                <div class="card-body">


                    <?php if (
                        $erro !== ""
                    ): ?>

                        <div class="alert alert-danger">

                            <?= htmlspecialchars(
                                $erro
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <form method="POST">


                        <div class="row g-3">


                            <!-- NOME -->

                            <div class="col-md-8">

                                <label class="form-label">

                                    Nome do local *

                                </label>

                                <input
                                    type="text"
                                    name="nome"
                                    class="form-control"
                                    maxlength="100"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados["nome"]
                                    ) ?>"
                                >

                            </div>


                            <!-- CAPACIDADE -->

                            <div class="col-md-4">

                                <label class="form-label">

                                    Capacidade máxima *

                                </label>

                                <input
                                    type="number"
                                    name="capacidade"
                                    class="form-control"
                                    min="1"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados["capacidade"]
                                    ) ?>"
                                >

                            </div>


                            <!-- DESCRIÇÃO -->

                            <div class="col-12">

                                <label class="form-label">

                                    Descrição

                                </label>

                                <textarea
                                    name="descricao"
                                    class="form-control"
                                    rows="3"
                                ><?= htmlspecialchars(
                                    $dados["descricao"]
                                ) ?></textarea>

                            </div>


                            <!-- VALOR -->

                            <div class="col-md-4">

                                <label class="form-label">

                                    Valor da reserva

                                </label>

                                <div class="input-group">

                                    <span class="input-group-text">

                                        R$

                                    </span>

                                    <input
                                        type="text"
                                        name="valor"
                                        id="valor"
                                        class="form-control"
                                        value="<?= htmlspecialchars(
                                            $dados["valor"]
                                        ) ?>"
                                    >

                                </div>

                            </div>


                            <!-- GRATUITO -->

                            <div class="col-md-4 d-flex align-items-end">

                                <div class="form-check mb-2">

                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="gratuito"
                                        id="gratuito"
                                        <?= marcado(
                                            $dados,
                                            "gratuito"
                                        ) ?>
                                    >

                                    <label
                                        class="form-check-label"
                                        for="gratuito"
                                    >

                                        Este local é gratuito

                                    </label>

                                </div>

                            </div>


                            <!-- ATIVO -->

                            <div class="col-md-4 d-flex align-items-end">

                                <div class="form-check mb-2">

                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="ativo"
                                        id="ativo"
                                        <?= marcado(
                                            $dados,
                                            "ativo"
                                        ) ?>
                                    >

                                    <label
                                        class="form-check-label"
                                        for="ativo"
                                    >

                                        Local ativo

                                    </label>

                                </div>

                            </div>


                            <!-- REGRAS -->

                            <div class="col-12">

                                <hr>

                                <h5>

                                    ⏱️ Regras de duração e horário

                                </h5>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Duração mínima (horas) *

                                </label>

                                <input
                                    type="number"
                                    name="duracao_minima_horas"
                                    class="form-control"
                                    min="0.5"
                                    step="0.5"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados[
                                            "duracao_minima_horas"
                                        ]
                                    ) ?>"
                                >

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Duração máxima (horas) *

                                </label>

                                <input
                                    type="number"
                                    name="duracao_maxima_horas"
                                    class="form-control"
                                    min="0.5"
                                    step="0.5"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados[
                                            "duracao_maxima_horas"
                                        ]
                                    ) ?>"
                                >

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Horário inicial permitido *

                                </label>

                                <input
                                    type="time"
                                    name="horario_inicio"
                                    class="form-control"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados[
                                            "horario_inicio"
                                        ]
                                    ) ?>"
                                >

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Horário final permitido *

                                </label>

                                <input
                                    type="time"
                                    name="horario_fim"
                                    class="form-control"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados[
                                            "horario_fim"
                                        ]
                                    ) ?>"
                                >

                            </div>


                            <!-- ANTECEDÊNCIA -->

                            <div class="col-12">

                                <hr>

                                <h5>

                                    📅 Antecedência

                                </h5>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Antecedência mínima (dias)

                                </label>

                                <input
                                    type="number"
                                    name="antecedencia_min_dias"
                                    class="form-control"
                                    min="0"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados[
                                            "antecedencia_min_dias"
                                        ]
                                    ) ?>"
                                >

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Antecedência máxima (dias)

                                </label>

                                <input
                                    type="number"
                                    name="antecedencia_max_dias"
                                    class="form-control"
                                    min="0"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados[
                                            "antecedencia_max_dias"
                                        ]
                                    ) ?>"
                                >

                            </div>


                            <!-- LIMITE -->

                            <div class="col-12">

                                <hr>

                                <h5>

                                    🔒 Limite por unidade

                                </h5>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Quantidade máxima de reservas por unidade *

                                </label>

                                <input
                                    type="number"
                                    name="limite_reservas_unidade"
                                    class="form-control"
                                    min="1"
                                    required
                                    value="<?= htmlspecialchars(
                                        $dados[
                                            "limite_reservas_unidade"
                                        ]
                                    ) ?>"
                                >

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">

                                    Período do limite *

                                </label>

                                <select
                                    name="periodo_limite"
                                    class="form-select"
                                    required
                                >

                                    <option
                                        value="Dia"
                                        <?= $dados[
                                            "periodo_limite"
                                        ] === "Dia"
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        Por dia

                                    </option>


                                    <option
                                        value="Mes"
                                        <?= $dados[
                                            "periodo_limite"
                                        ] === "Mes"
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        Por mês

                                    </option>


                                    <option
                                        value="Total"
                                        <?= $dados[
                                            "periodo_limite"
                                        ] === "Total"
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        Total

                                    </option>

                                </select>

                            </div>


                            <!-- DIAS -->

                            <div class="col-12">

                                <hr>

                                <h5>

                                    🗓️ Dias permitidos

                                </h5>

                            </div>


                            <?php

                            $dias = [

                                "domingo"
                                    => "Domingo",

                                "segunda"
                                    => "Segunda-feira",

                                "terca"
                                    => "Terça-feira",

                                "quarta"
                                    => "Quarta-feira",

                                "quinta"
                                    => "Quinta-feira",

                                "sexta"
                                    => "Sexta-feira",

                                "sabado"
                                    => "Sábado"

                            ];

                            foreach (
                                $dias
                                as $campo
                                => $nomeDia
                            ):

                            ?>

                                <div class="col-6 col-md-3 col-lg-2">

                                    <div class="form-check">

                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="<?= $campo ?>"
                                            id="<?= $campo ?>"
                                            <?= marcado(
                                                $dados,
                                                $campo
                                            ) ?>
                                        >

                                        <label
                                            class="form-check-label"
                                            for="<?= $campo ?>"
                                        >

                                            <?= $nomeDia ?>

                                        </label>

                                    </div>

                                </div>

                            <?php endforeach; ?>


                        </div>


                        <hr class="my-4">


                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-warning"
                            >

                                Salvar Alterações

                            </button>


                            <a
                                href="locais.php"
                                class="btn btn-secondary"
                            >

                                Cancelar

                            </a>

                        </div>


                    </form>


                </div>

            </div>

        </div>

    </div>

</div>


<script>

const gratuito =
    document.getElementById(
        "gratuito"
    );

const valor =
    document.getElementById(
        "valor"
    );


function atualizarValor()
{
    valor.disabled =
        gratuito.checked;


    if (
        gratuito.checked
    ) {

        valor.value =
            "0,00";
    }
}


gratuito.addEventListener(
    "change",
    atualizarValor
);


atualizarValor();

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>