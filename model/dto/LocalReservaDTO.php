<?php

class LocalReservaDTO
{
    private ?int $id = null;

    private string $nome = "";

    private string $descricao = "";

    private int $capacidade = 1;

    private float $valor = 0.00;

    private bool $gratuito = true;

    private int $duracao_minutos_minima = 60;

    private int $duracao_minutos_maxima = 1440;

    private string $horario_inicio = "08:00";

    private string $horario_fim = "23:00";

    private int $antecedencia_min_dias = 0;

    private int $antecedencia_max_dias = 30;

    private int $limite_reservas_unidade = 1;

    private string $periodo_limite = "Dia";

    private bool $domingo = false;

    private bool $segunda = true;

    private bool $terca = true;

    private bool $quarta = true;

    private bool $quinta = true;

    private bool $sexta = true;

    private bool $sabado = true;

    private bool $ativo = true;

    private ?string $fotos = null;

    private ?string $created_at = null;

    private ?string $updated_at = null;


    /*
     * FOTOS DO LOCAL
     */

    public function getFotos(): ?string
    {
        return $this->fotos;
    }

    public function setFotos(?string $fotos): void
    {
        $this->fotos = $fotos;
    }

    public function getFotosArray(): array
    {
        if (!$this->fotos) {
            return [];
        }

        $dados = json_decode($this->fotos, true);

        return is_array($dados) ? array_values(array_filter($dados, 'is_string')) : [];
    }


    /*
     * ID
     */

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }


    /*
     * NOME
     */

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): void
    {
        $this->nome = $nome;
    }


    /*
     * DESCRIÇÃO
     */

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): void
    {
        $this->descricao = $descricao;
    }


    /*
     * CAPACIDADE
     */

    public function getCapacidade(): int
    {
        return $this->capacidade;
    }

    public function setCapacidade(int $capacidade): void
    {
        $this->capacidade = $capacidade;
    }


    /*
     * VALOR
     */

    public function getValor(): float
    {
        return $this->valor;
    }

    public function setValor(float $valor): void
    {
        $this->valor = $valor;
    }


    /*
     * GRATUITO
     */

    public function getGratuito(): bool
    {
        return $this->gratuito;
    }

    public function setGratuito(bool $gratuito): void
    {
        $this->gratuito = $gratuito;
    }

    public function isGratuito(): bool
    {
        return $this->gratuito;
    }


    /*
     * DURAÇÃO MÍNIMA
     */

    public function getDuracaoMinutosMinima(): int
    {
        return $this->duracao_minutos_minima;
    }

    public function setDuracaoMinutosMinima(int $valor): void
    {
        $this->duracao_minutos_minima = $valor;
    }


    /*
     * DURAÇÃO MÁXIMA
     */

    public function getDuracaoMinutosMaxima(): int
    {
        return $this->duracao_minutos_maxima;
    }

    public function setDuracaoMinutosMaxima(int $valor): void
    {
        $this->duracao_minutos_maxima = $valor;
    }


    /*
     * HORÁRIO INICIAL
     */

    public function getHorarioInicio(): string
    {
        return $this->horario_inicio;
    }

    public function setHorarioInicio(string $valor): void
    {
        $this->horario_inicio = $valor;
    }


    /*
     * HORÁRIO FINAL
     */

    public function getHorarioFim(): string
    {
        return $this->horario_fim;
    }

    public function setHorarioFim(string $valor): void
    {
        $this->horario_fim = $valor;
    }


    /*
     * ANTECEDÊNCIA MÍNIMA
     */

    public function getAntecedenciaMinDias(): int
    {
        return $this->antecedencia_min_dias;
    }

    public function setAntecedenciaMinDias(int $valor): void
    {
        $this->antecedencia_min_dias = $valor;
    }


    /*
     * ANTECEDÊNCIA MÁXIMA
     */

    public function getAntecedenciaMaxDias(): int
    {
        return $this->antecedencia_max_dias;
    }

    public function setAntecedenciaMaxDias(int $valor): void
    {
        $this->antecedencia_max_dias = $valor;
    }


    /*
     * LIMITE POR UNIDADE
     */

    public function getLimiteReservasUnidade(): int
    {
        return $this->limite_reservas_unidade;
    }

    public function setLimiteReservasUnidade(int $valor): void
    {
        $this->limite_reservas_unidade = $valor;
    }


    /*
     * PERÍODO DO LIMITE
     */

    public function getPeriodoLimite(): string
    {
        return $this->periodo_limite;
    }

    public function setPeriodoLimite(string $valor): void
    {
        $this->periodo_limite = $valor;
    }


    /*
     * DOMINGO
     */

    public function getDomingo(): bool
    {
        return $this->domingo;
    }

    public function setDomingo(bool $valor): void
    {
        $this->domingo = $valor;
    }


    /*
     * SEGUNDA
     */

    public function getSegunda(): bool
    {
        return $this->segunda;
    }

    public function setSegunda(bool $valor): void
    {
        $this->segunda = $valor;
    }


    /*
     * TERÇA
     */

    public function getTerca(): bool
    {
        return $this->terca;
    }

    public function setTerca(bool $valor): void
    {
        $this->terca = $valor;
    }


    /*
     * QUARTA
     */

    public function getQuarta(): bool
    {
        return $this->quarta;
    }

    public function setQuarta(bool $valor): void
    {
        $this->quarta = $valor;
    }


    /*
     * QUINTA
     */

    public function getQuinta(): bool
    {
        return $this->quinta;
    }

    public function setQuinta(bool $valor): void
    {
        $this->quinta = $valor;
    }


    /*
     * SEXTA
     */

    public function getSexta(): bool
    {
        return $this->sexta;
    }

    public function setSexta(bool $valor): void
    {
        $this->sexta = $valor;
    }


    /*
     * SÁBADO
     */

    public function getSabado(): bool
    {
        return $this->sabado;
    }

    public function setSabado(bool $valor): void
    {
        $this->sabado = $valor;
    }


    /*
     * ATIVO
     */

    public function getAtivo(): bool
    {
        return $this->ativo;
    }

    public function setAtivo(bool $valor): void
    {
        $this->ativo = $valor;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }


    /*
     * CREATED AT
     */

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $valor): void
    {
        $this->created_at = $valor;
    }


    /*
     * UPDATED AT
     */

    public function getUpdatedAt(): ?string
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(?string $valor): void
    {
        $this->updated_at = $valor;
    }
}