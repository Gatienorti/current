package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func redisTestCertificates(t *testing.T) RedisTLS {
	t.Helper()
	root := t.TempDir()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Disposable Redis test CA"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	der, err := x509.CreateCertificate(rand.Reader, ca, ca, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	caPath := filepath.Join(root, "ca.pem")
	if err := os.WriteFile(caPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0600); err != nil {
		t.Fatal(err)
	}
	leaf := &x509.Certificate{SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "localhost"}, DNSNames: []string{"localhost"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth, x509.ExtKeyUsageClientAuth}}
	leafDER, err := x509.CreateCertificate(rand.Reader, leaf, ca, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	certPath, keyPath := filepath.Join(root, "leaf.pem"), filepath.Join(root, "leaf-key.pem")
	keyDER, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(certPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: leafDER}), 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(keyPath, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}), 0600); err != nil {
		t.Fatal(err)
	}
	return RedisTLS{CA: caPath, Cert: certPath, Key: keyPath, ServerName: "localhost"}
}

func TestRedisTLSStrictConfigurationAndSanitizedFailures(t *testing.T) {
	if config, err := redisTLS(false, RedisTLS{}); err != nil || config != nil {
		t.Fatal("plain TCP behavior changed")
	}
	config, err := redisTLS(true, RedisTLS{})
	if err != nil || config.MinVersion != tls.VersionTLS12 || config.InsecureSkipVerify {
		t.Fatal("TLS defaults do not verify peers")
	}
	settings := redisTestCertificates(t)
	config, err = redisTLS(true, settings)
	if err != nil || config.RootCAs == nil || config.ServerName != "localhost" || len(config.Certificates) != 1 || config.InsecureSkipVerify {
		t.Fatalf("custom TLS configuration: %v", err)
	}
	for _, invalid := range []RedisTLS{{CA: "/private/redis-certificate-secret"}, {Cert: settings.Cert}, {Key: settings.Key}, {ServerName: "invalid\x00host"}, {CA: settings.Key}} {
		if _, err := redisTLS(true, invalid); err == nil || strings.Contains(err.Error(), "/private/") {
			t.Fatalf("unsafe TLS input/error: %v", err)
		}
	}
	if _, err := redisTLS(false, settings); err == nil {
		t.Fatal("TLS options silently ignored for plaintext")
	}
	large := filepath.Join(t.TempDir(), "oversized.pem")
	if err := os.WriteFile(large, make([]byte, 1048577), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := redisTLS(true, RedisTLS{CA: large}); err == nil {
		t.Fatal("unbounded TLS file accepted")
	}
}

func TestRedisTLSActualMutualHandshakeAndIdentityRejection(t *testing.T) {
	settings := redisTestCertificates(t)
	config, err := redisTLS(true, settings)
	if err != nil {
		t.Fatal(err)
	}
	listener, err := tls.Listen("tcp", "127.0.0.1:0", &tls.Config{Certificates: config.Certificates, ClientCAs: config.RootCAs, ClientAuth: tls.RequireAndVerifyClientCert, MinVersion: tls.VersionTLS12})
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	done := make(chan struct{})
	go func() {
		defer close(done)
		for i := 0; i < 3; i++ {
			connection, err := listener.Accept()
			if err != nil {
				return
			}
			_ = connection.SetDeadline(time.Now().Add(time.Second))
			_ = connection.(*tls.Conn).Handshake()
			connection.Close()
		}
	}()
	dial := &net.Dialer{Timeout: time.Second}
	connection, err := tls.DialWithDialer(dial, "tcp", listener.Addr().String(), config)
	if err != nil {
		t.Fatalf("valid mutual TLS failed: %v", err)
	}
	connection.Close()
	wrongName := config.Clone()
	wrongName.ServerName = "other-host.invalid"
	if connection, err := tls.DialWithDialer(dial, "tcp", listener.Addr().String(), wrongName); err == nil {
		connection.Close()
		t.Fatal("wrong server name accepted")
	}
	wrongCA := config.Clone()
	wrongCA.RootCAs = x509.NewCertPool()
	if connection, err := tls.DialWithDialer(dial, "tcp", listener.Addr().String(), wrongCA); err == nil {
		connection.Close()
		t.Fatal("untrusted CA accepted")
	}
	<-done
}
