<?php

class AutorizacaoAcessoDTO
{
    private ?int $id = null;
    private ?int $visitanteId = null;
    private ?int $unidadeId = null;
    private ?int $usuarioAutorizadorId = null;

    private ?string $dataInicio = null;
    private ?string $dataFim = null;
    private ?string $qrCode = null;
    private string $status = "Ativo";

    private ?string $visitanteNome = null;
    private ?string $visitanteDocumento = null;
    private ?string $visitanteFoto = null;
    private ?string $unidadeIdentificacao = null;
    private ?string $usuarioAutorizadorNome = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getVisitanteId(): ?int
    {
        return $this->visitanteId;
    }

    public function setVisitanteId(?int $visitanteId): void
    {
        $this->visitanteId = $visitanteId;
    }

    public function getUnidadeId(): ?int
    {
        return $this->unidadeId;
    }

    public function setUnidadeId(?int $unidadeId): void
    {
        $this->unidadeId = $unidadeId;
    }

    public function getUsuarioAutorizadorId(): ?int
    {
        return $this->usuarioAutorizadorId;
    }

    public function setUsuarioAutorizadorId(?int $usuarioAutorizadorId): void
    {
        $this->usuarioAutorizadorId = $usuarioAutorizadorId;
    }

    public function getDataInicio(): ?string
    {
        return $this->dataInicio;
    }

    public function setDataInicio(?string $dataInicio): void
    {
        $this->dataInicio = $dataInicio;
    }

    public function getDataFim(): ?string
    {
        return $this->dataFim;
    }

    public function setDataFim(?string $dataFim): void
    {
        $this->dataFim = $dataFim;
    }

    public function getQrCode(): ?string
    {
        return $this->qrCode;
    }

    public function setQrCode(?string $qrCode): void
    {
        $this->qrCode = $qrCode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getVisitanteNome(): ?string
    {
        return $this->visitanteNome;
    }

    public function setVisitanteNome(?string $visitanteNome): void
    {
        $this->visitanteNome = $visitanteNome;
    }

    public function getVisitanteDocumento(): ?string
    {
        return $this->visitanteDocumento;
    }

    public function setVisitanteDocumento(?string $visitanteDocumento): void
    {
        $this->visitanteDocumento = $visitanteDocumento;
    }

    public function getVisitanteFoto(): ?string
    {
        return $this->visitanteFoto;
    }

    public function setVisitanteFoto(?string $visitanteFoto): void
    {
        $this->visitanteFoto = $visitanteFoto;
    }

    public function getUnidadeIdentificacao(): ?string
    {
        return $this->unidadeIdentificacao;
    }

    public function setUnidadeIdentificacao(?string $unidadeIdentificacao): void
    {
        $this->unidadeIdentificacao = $unidadeIdentificacao;
    }

    public function getUsuarioAutorizadorNome(): ?string
    {
        return $this->usuarioAutorizadorNome;
    }

    public function setUsuarioAutorizadorNome(?string $usuarioAutorizadorNome): void
    {
        $this->usuarioAutorizadorNome = $usuarioAutorizadorNome;
    }
}