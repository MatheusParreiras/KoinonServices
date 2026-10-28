<?php

class MoradorDashboardDTO
{
    private ?array $usuario = null;
    private ?array $unidade = null;

    private array $veiculos = [];
    private array $encomendas = [];
    private array $reservas = [];
    private array $ocorrencias = [];
    private array $cobrancas = [];
    private array $autorizacoes = [];
    private array $publicacoes = [];
    private array $documentos = [];


    public function getUsuario(): ?array
    {
        return $this->usuario;
    }

    public function setUsuario(?array $usuario): void
    {
        $this->usuario = $usuario;
    }


    public function getUnidade(): ?array
    {
        return $this->unidade;
    }

    public function setUnidade(?array $unidade): void
    {
        $this->unidade = $unidade;
    }


    public function getVeiculos(): array
    {
        return $this->veiculos;
    }

    public function setVeiculos(array $veiculos): void
    {
        $this->veiculos = $veiculos;
    }


    public function getEncomendas(): array
    {
        return $this->encomendas;
    }

    public function setEncomendas(array $encomendas): void
    {
        $this->encomendas = $encomendas;
    }


    public function getReservas(): array
    {
        return $this->reservas;
    }

    public function setReservas(array $reservas): void
    {
        $this->reservas = $reservas;
    }


    public function getOcorrencias(): array
    {
        return $this->ocorrencias;
    }

    public function setOcorrencias(array $ocorrencias): void
    {
        $this->ocorrencias = $ocorrencias;
    }


    public function getCobrancas(): array
    {
        return $this->cobrancas;
    }

    public function setCobrancas(array $cobrancas): void
    {
        $this->cobrancas = $cobrancas;
    }


    public function getAutorizacoes(): array
    {
        return $this->autorizacoes;
    }

    public function setAutorizacoes(array $autorizacoes): void
    {
        $this->autorizacoes = $autorizacoes;
    }


    public function getPublicacoes(): array
    {
        return $this->publicacoes;
    }

    public function setPublicacoes(array $publicacoes): void
    {
        $this->publicacoes = $publicacoes;
    }


    public function getDocumentos(): array
    {
        return $this->documentos;
    }

    public function setDocumentos(array $documentos): void
    {
        $this->documentos = $documentos;
    }
}