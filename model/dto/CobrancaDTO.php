<?php

class CobrancaDTO
{
    private ?int $id = null;
    private ?int $unidade_id = null;
    private ?string $unidade_identificacao = null;
    private string $tipo_cobranca = "Taxa_Condominial";
    private string $mes_referencia = "";
    private string $descricao = "Taxa Condominial Ordinária";
    private float $valor = 0.00;
    private ?string $vencimento = null;
    private ?string $linha_digitavel = null;
    private ?string $link_boleto = null;
    private string $status = "Pendente";
    private ?string $data_pagamento = null;
    private ?string $forma_pagamento = null;
    private ?string $codigo_transacao = null;
    private ?string $created_at = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getUnidadeId(): ?int
    {
        return $this->unidade_id;
    }

    public function setUnidadeId(?int $unidade_id): void
    {
        $this->unidade_id = $unidade_id;
    }

    public function getUnidadeIdentificacao(): ?string
    {
        return $this->unidade_identificacao;
    }

    public function setUnidadeIdentificacao(?string $unidade_identificacao): void
    {
        $this->unidade_identificacao = $unidade_identificacao;
    }

    public function getTipoCobranca(): string
    {
        return $this->tipo_cobranca;
    }

    public function setTipoCobranca(string $tipo_cobranca): void
    {
        $this->tipo_cobranca = $tipo_cobranca;
    }

    public function getMesReferencia(): string
    {
        return $this->mes_referencia;
    }

    public function setMesReferencia(string $mes_referencia): void
    {
        $this->mes_referencia = $mes_referencia;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): void
    {
        $this->descricao = $descricao;
    }

    public function getValor(): float
    {
        return $this->valor;
    }

    public function setValor(float $valor): void
    {
        $this->valor = $valor;
    }

    public function getVencimento(): ?string
    {
        return $this->vencimento;
    }

    public function setVencimento(?string $vencimento): void
    {
        $this->vencimento = $vencimento;
    }

    public function getLinhaDigitavel(): ?string
    {
        return $this->linha_digitavel;
    }

    public function setLinhaDigitavel(?string $linha_digitavel): void
    {
        $this->linha_digitavel = $linha_digitavel;
    }

    public function getLinkBoleto(): ?string
    {
        return $this->link_boleto;
    }

    public function setLinkBoleto(?string $link_boleto): void
    {
        $this->link_boleto = $link_boleto;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getDataPagamento(): ?string
    {
        return $this->data_pagamento;
    }

    public function setDataPagamento(?string $data_pagamento): void
    {
        $this->data_pagamento = $data_pagamento;
    }

    public function getFormaPagamento(): ?string
    {
        return $this->forma_pagamento;
    }

    public function setFormaPagamento(?string $forma_pagamento): void
    {
        $this->forma_pagamento = $forma_pagamento;
    }

    public function getCodigoTransacao(): ?string
    {
        return $this->codigo_transacao;
    }

    public function setCodigoTransacao(?string $codigo_transacao): void
    {
        $this->codigo_transacao = $codigo_transacao;
    }

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $created_at): void
    {
        $this->created_at = $created_at;
    }
}