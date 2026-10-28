<?php

class EncomendaDTO
{
    private ?int $id = null;

    private ?int $unidadeId = null;

    private ?int $usuarioRecebedorId = null;

    private ?string $descricao = null;

    private ?string $fotoUrl = null;

    private ?string $codigoRastreio = null;

    private ?string $dataRecebimento = null;

    private ?string $dataRetirada = null;

    private string $status = "Aguardando_Retirada";

    private ?string $unidadeIdentificacao = null;

    private ?string $usuarioNome = null;


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
        return $this->unidadeId;
    }


    public function setUnidadeId(?int $unidadeId): void
    {
        $this->unidadeId = $unidadeId;
    }


    public function getUsuarioRecebedorId(): ?int
    {
        return $this->usuarioRecebedorId;
    }


    public function setUsuarioRecebedorId(?int $usuarioRecebedorId): void
    {
        $this->usuarioRecebedorId = $usuarioRecebedorId;
    }


    public function getDescricao(): ?string
    {
        return $this->descricao;
    }


    public function setDescricao(?string $descricao): void
    {
        $this->descricao = $descricao;
    }


    public function getFotoUrl(): ?string
    {
        return $this->fotoUrl;
    }


    public function setFotoUrl(?string $fotoUrl): void
    {
        $this->fotoUrl = $fotoUrl;
    }


    public function getCodigoRastreio(): ?string
    {
        return $this->codigoRastreio;
    }


    public function setCodigoRastreio(?string $codigoRastreio): void
    {
        $this->codigoRastreio = $codigoRastreio;
    }


    public function getDataRecebimento(): ?string
    {
        return $this->dataRecebimento;
    }


    public function setDataRecebimento(?string $dataRecebimento): void
    {
        $this->dataRecebimento = $dataRecebimento;
    }


    public function getDataRetirada(): ?string
    {
        return $this->dataRetirada;
    }


    public function setDataRetirada(?string $dataRetirada): void
    {
        $this->dataRetirada = $dataRetirada;
    }


    public function getStatus(): string
    {
        return $this->status;
    }


    public function setStatus(string $status): void
    {
        $this->status = $status;
    }


    public function getUnidadeIdentificacao(): ?string
    {
        return $this->unidadeIdentificacao;
    }


    public function setUnidadeIdentificacao(
        ?string $unidadeIdentificacao
    ): void {
        $this->unidadeIdentificacao = $unidadeIdentificacao;
    }


    public function getUsuarioNome(): ?string
    {
        return $this->usuarioNome;
    }


    public function setUsuarioNome(?string $usuarioNome): void
    {
        $this->usuarioNome = $usuarioNome;
    }
}