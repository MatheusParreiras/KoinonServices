<?php

class RegistroAcessoDTO
{
    private ?int $id = null;
    private ?int $usuario_id = null;
    private ?int $visitante_id = null;
    private ?string $usuario_nome = null;
    private ?string $usuario_cpf = null;
    private ?string $visitante_nome = null;
    private ?string $visitante_documento = null;
    private ?string $visitante_tipo = null;
    private ?string $autorizador_nome = null;
    private ?string $autorizador_login = null;
    private ?string $autorizador_perfil = null;
    private ?int $autorizado_por_usuario_id = null;
    private ?string $data_hora = null;
    private string $direcao = "Entrada";
    private string $metodo_leitura = "Manual";
    private ?string $foto_evento_url = null;
    private ?string $observacao = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getUsuarioId(): ?int
    {
        return $this->usuario_id;
    }

    public function setUsuarioId(?int $usuario_id): void
    {
        $this->usuario_id = $usuario_id;
    }

    public function getVisitanteId(): ?int
    {
        return $this->visitante_id;
    }

    public function setVisitanteId(?int $visitante_id): void
    {
        $this->visitante_id = $visitante_id;
    }

    public function getUsuarioCpf(): ?string
    {
        return $this->usuario_cpf;
    }

    public function setUsuarioCpf(?string $usuario_cpf): void
    {
        $this->usuario_cpf = $usuario_cpf;
    }

    public function getUsuarioNome(): ?string
    {
        return $this->usuario_nome;
    }

    public function setUsuarioNome(?string $usuario_nome): void
    {
        $this->usuario_nome = $usuario_nome;
    }

    public function getVisitanteNome(): ?string
    {
        return $this->visitante_nome;
    }

    public function setVisitanteNome(?string $visitante_nome): void
    {
        $this->visitante_nome = $visitante_nome;
    }

    public function getVisitanteDocumento(): ?string
    {
        return $this->visitante_documento;
    }

    public function setVisitanteDocumento(?string $visitante_documento): void
    {
        $this->visitante_documento = $visitante_documento;
    }

    public function getAutorizadorNome(): ?string
    {
        return $this->autorizador_nome;
    }

    public function setAutorizadorNome(?string $autorizador_nome): void
    {
        $this->autorizador_nome = $autorizador_nome;
    }

    public function getAutorizadoPorUsuarioId(): ?int
    {
        return $this->autorizado_por_usuario_id;
    }

    public function setAutorizadoPorUsuarioId(?int $id): void
    {
        $this->autorizado_por_usuario_id = $id;
    }

    public function getAutorizadorPerfil(): ?string
    {
        return $this->autorizador_perfil;
    }

    public function setAutorizadorPerfil(?string $perfil): void
    {
        $this->autorizador_perfil = $perfil;
    }

    public function getAutorizadorLogin(): ?string
    {
        return $this->autorizador_login;
    }

    public function setAutorizadorLogin(?string $autorizador_login): void
    {
        $this->autorizador_login = $autorizador_login;
    }

    public function getVisitanteTipo(): ?string
    {
        return $this->visitante_tipo;
    }

    public function setVisitanteTipo(?string $visitante_tipo): void
    {
        $this->visitante_tipo = $visitante_tipo;
    }

    public function getDataHora(): ?string
    {
        return $this->data_hora;
    }

    public function setDataHora(?string $data_hora): void
    {
        $this->data_hora = $data_hora;
    }

    public function getDirecao(): string
    {
        return $this->direcao;
    }

    public function setDirecao(string $direcao): void
    {
        $this->direcao = $direcao;
    }

    public function getMetodoLeitura(): string
    {
        return $this->metodo_leitura;
    }

    public function setMetodoLeitura(string $metodo_leitura): void
    {
        $this->metodo_leitura = $metodo_leitura;
    }

    public function getFotoEventoUrl(): ?string
    {
        return $this->foto_evento_url;
    }

    public function setFotoEventoUrl(?string $foto_evento_url): void
    {
        $this->foto_evento_url = $foto_evento_url;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function setObservacao(?string $observacao): void
    {
        $this->observacao = $observacao;
    }
}